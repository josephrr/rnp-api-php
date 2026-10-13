<?php

declare(strict_types=1);

namespace Egobytes\Rnp;

/**
 * Cliente del API de consulta vehicular del Registro Nacional de Costa Rica.
 *
 *     $rnp = new Cliente('rnp_su_clave');
 *     $consulta = $rnp->porPlaca('BJK123');
 *
 *     echo $consulta->campo('marca');
 *     echo $consulta->saldo->total;
 *     echo $consulta->vencimientoDekra()['fecha'] ?? 'sin fecha';
 *
 * Sin dependencias: solo cURL, que viene con PHP. Tres endpoints no
 * justifican arrastrar un stack HTTP entero al proyecto de nadie.
 */
final class Cliente
{
    public const VERSION = '1.1.0';

    /** Lo que se escribe en `clase`, en mayúsculas, => la clave del contrato. */
    private const CLASES = [
        'PARTICULAR'   => 'particular',
        'CL'           => 'CL',
        'MOT'          => 'MOT',
        'M'            => 'MOT',
        'MOTO'         => 'MOT',
        'MOTOCICLETA'  => 'MOT',
    ];

    private const BASE_POR_DEFECTO = 'https://rnp.egobytes.com/api/v1';

    /** El Registro suele tardar entre 3 y 10 segundos; 45 deja margen de sobra. */
    private const TIEMPO_LIMITE = 45;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $base = self::BASE_POR_DEFECTO,
        /** Reintentos ante fallos del Registro. 0 los desactiva. */
        private readonly int $reintentos = 2,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Falta la API key. Se genera en el portal, sección Credenciales.');
        }
    }

    /** Toma la clave del entorno, que es donde debería estar. */
    public static function desdeEntorno(string $variable = 'RNP_API_KEY'): self
    {
        $clave = getenv($variable) ?: ($_ENV[$variable] ?? $_SERVER[$variable] ?? '');

        if (!is_string($clave) || $clave === '') {
            throw new \InvalidArgumentException("La variable de entorno {$variable} está vacía.");
        }

        $base = getenv('RNP_API_URL') ?: self::BASE_POR_DEFECTO;

        return new self($clave, is_string($base) ? $base : self::BASE_POR_DEFECTO);
    }

    /**
     * Consulta por placa.
     *
     * En carga liviana y en moto va solo el número: el `CL` o la `M` son la
     * clase, no parte de la placa. `porPlaca('272490', 'CL')`, no
     * `porPlaca('CL272490')`. En moto, `porPlaca('123456', 'MOT')`.
     *
     * @throws RnpException
     */
    public function porPlaca(string $placa, string $clase = 'particular'): Consulta
    {
        return Consulta::desdeArray($this->consultar($this->rutaDePlaca($placa, $clase, '/vehiculos/placa/', 10)));
    }

    /**
     * Solo el día en que vence la revisión técnica, según Dekra.
     *
     * No consulta el Registro y no descuenta saldo: `cobrada` es siempre
     * false. Si Dekra no publica fecha, `datos` llega en null y no es un error.
     *
     * @throws RnpException
     */
    public function dekraPorPlaca(string $placa, string $clase = 'particular'): ConsultaDekra
    {
        return ConsultaDekra::desdeArray($this->consultar($this->rutaDePlaca($placa, $clase, '/dekra/placa/', 12)));
    }

    /**
     * Consulta por número de VIN o chasis.
     *
     * @throws RnpException
     */
    public function porVin(string $vin): Consulta
    {
        $vin = strtoupper(trim($vin));

        if (!preg_match('/^[A-Z0-9]{3,20}$/', $vin)) {
            throw new \InvalidArgumentException('El VIN se escribe sin guiones ni espacios.');
        }

        return Consulta::desdeArray($this->consultar('/vehiculos/vin/' . rawurlencode($vin)));
    }

    /**
     * La clave canónica de la clase, o una excepción si no es ninguna.
     *
     * Acepta lo que dice la placa (`M`) y lo que dice el contrato (`MOT`),
     * sin distinguir mayúsculas.
     */
    public static function normalizarClase(string $clase): string
    {
        $canonica = self::CLASES[strtoupper(trim($clase))] ?? null;

        if ($canonica === null) {
            throw new \InvalidArgumentException("Clase no válida: '{$clase}'. Use 'particular', 'CL' o 'MOT'.");
        }

        return $canonica;
    }

    /** Arma la ruta de una placa. El largo lo pone el endpoint, no la clase. */
    private function rutaDePlaca(string $placa, string $clase, string $prefijo, int $maximo): string
    {
        $placa = strtoupper(trim($placa));

        if (!preg_match('/^[A-Z0-9]{1,' . $maximo . '}$/', $placa)) {
            throw new \InvalidArgumentException('La placa se escribe sin guiones ni espacios.');
        }

        $clase = self::normalizarClase($clase);
        $ruta = $prefijo . rawurlencode($placa);

        return $clase === 'particular' ? $ruta : $ruta . '?clase=' . rawurlencode($clase);
    }

    /**
     * Reintenta solo lo que puede cambiar de resultado.
     *
     * Un 402 o un 404 dan lo mismo diez veces; reintentarlos solo gasta tiempo.
     * El límite por minuto sí se respeta esperando lo que el propio API indica.
     *
     * @return array<string, mixed>
     */
    private function consultar(string $ruta): array
    {
        $intento = 0;

        while (true) {
            try {
                return $this->pedir($ruta);
            } catch (RnpException $e) {
                if ($intento >= $this->reintentos || !$e->esReintentable()) {
                    throw $e;
                }

                // Si el API dijo cuánto esperar, se le hace caso; si no,
                // retroceso exponencial: 1 s, 2 s, 4 s…
                sleep($e->reintentarEn ?? (2 ** $intento));
                $intento++;
            }
        }
    }

    /** @return array<string, mixed> */
    private function pedir(string $ruta): array
    {
        $ch = curl_init(rtrim($this->base, '/') . $ruta);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIEMPO_LIMITE,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: rnp-php/' . self::VERSION . ' php/' . PHP_VERSION,
            ],
        ]);

        $respuesta = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errorCurl = curl_error($ch);

        curl_close($ch);

        if ($respuesta === false) {
            throw RnpException::sinRespuesta($errorCurl !== '' ? $errorCurl : 'sin detalle');
        }

        $cuerpo = json_decode((string) $respuesta, true);

        if (!is_array($cuerpo)) {
            throw new RnpException(
                codigo: 'respuesta_ilegible',
                mensaje: 'El API devolvió algo que no es JSON (HTTP ' . $status . ').',
                httpStatus: $status,
            );
        }

        if ($status >= 400 || isset($cuerpo['error'])) {
            throw RnpException::desdeRespuesta($cuerpo, $status);
        }

        return $cuerpo;
    }
}
