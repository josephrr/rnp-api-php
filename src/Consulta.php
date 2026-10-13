<?php

declare(strict_types=1);

namespace Egobytes\Rnp;

/**
 * Una consulta que salió bien.
 *
 * Da acceso crudo a los datos —`$consulta->datos`— para quien quiera el array
 * tal cual, y accesos con nombre para lo que se usa a diario. Deliberadamente
 * no se envuelve cada campo del vehículo en un objeto: el Registro cambia sus
 * campos de vez en cuando, y un modelo rígido convertiría cada cambio suyo en
 * una versión nueva de este paquete.
 */
final readonly class Consulta
{
    private function __construct(
        /** @var array<string, mixed> El cuerpo de `datos`, sin tocar. */
        public array $datos,
        /** Si esta consulta descontó del saldo. */
        public bool $cobrada,
        /** 'completa' o 'parcial'. Una parcial nunca se cobra. */
        public string $completitud,
        /** @var array<int, string> Qué faltó, cuando la respuesta es parcial. */
        public array $seccionesAusentes,
        /** true en las placas DEMO: datos ficticios, sin tocar el Registro. */
        public bool $sandbox,
        public ?Saldo $saldo,
        public ?string $requestId,
        public ?string $consultadoEn,
    ) {}

    public static function desdeArray(array $cuerpo): self
    {
        return new self(
            datos: $cuerpo['datos'] ?? [],
            cobrada: (bool) ($cuerpo['cobrada'] ?? false),
            completitud: $cuerpo['completitud'] ?? 'completa',
            seccionesAusentes: $cuerpo['secciones_ausentes'] ?? [],
            sandbox: (bool) ($cuerpo['sandbox'] ?? false),
            saldo: isset($cuerpo['saldo']) && is_array($cuerpo['saldo']) ? Saldo::desdeArray($cuerpo['saldo']) : null,
            requestId: $cuerpo['request_id'] ?? null,
            consultadoEn: $cuerpo['consultado_en'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function vehiculo(): array
    {
        return $this->datos['vehiculo'] ?? [];
    }

    /** @return array<string, mixed> */
    public function motor(): array
    {
        return $this->datos['motor'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function propietarios(): array
    {
        return $this->datos['propietarios'] ?? [];
    }

    public function gravamenes(): EstadoRegistral
    {
        return EstadoRegistral::desdeArray($this->datos['gravamenes'] ?? null);
    }

    public function anotaciones(): EstadoRegistral
    {
        return EstadoRegistral::desdeArray($this->datos['anotaciones'] ?? null);
    }

    public function infracciones(): EstadoRegistral
    {
        return EstadoRegistral::desdeArray($this->datos['infracciones'] ?? null);
    }

    public function levantamientos(): EstadoRegistral
    {
        return EstadoRegistral::desdeArray($this->datos['levantamientos'] ?? null);
    }

    /**
     * El día en que vence la revisión técnica según Dekra, o null.
     *
     * Trae `fecha` (`AAAA-MM-DD`), `placa` y `vigente`. `vigente` es true si
     * esa fecha es hoy o posterior, en hora de Costa Rica. No es el mes de
     * `inspeccionTecnica()`, que se deduce del último dígito.
     *
     * @return array<string, mixed>|null
     */
    public function vencimientoDekra(): ?array
    {
        return $this->objeto('vencimiento_dekra');
    }

    /**
     * El mes en que toca la inspección, deducido del último dígito de la placa.
     *
     * @return array<string, mixed>|null
     */
    public function inspeccionTecnica(): ?array
    {
        return $this->objeto('inspeccion_tecnica');
    }

    /**
     * Marchamo y traspaso estimados sobre el valor fiscal.
     *
     * Cada una en null cuando el Registro no publica ese valor. Son
     * referenciales: no es lo que cobran el INS ni la notaría.
     *
     * @return array{marchamo: array<string, mixed>|null, traspaso: array<string, mixed>|null}
     */
    public function estimaciones(): array
    {
        $datos = $this->datos['estimaciones'] ?? null;

        if (!is_array($datos)) {
            return ['marchamo' => null, 'traspaso' => null];
        }

        return [
            'marchamo' => is_array($datos['marchamo'] ?? null) ? $datos['marchamo'] : null,
            'traspaso' => is_array($datos['traspaso'] ?? null) ? $datos['traspaso'] : null,
        ];
    }

    /**
     * País y planta según el VIN, o null si la NHTSA no lo conoce.
     *
     * @return array<string, mixed>|null
     */
    public function origen(): ?array
    {
        return $this->objeto('origen');
    }

    /** @return array<string, mixed>|null */
    private function objeto(string $clave): ?array
    {
        $datos = $this->datos[$clave] ?? null;

        return is_array($datos) ? $datos : null;
    }

    /**
     * Un campo del vehículo, o null si el Registro no lo publica.
     *
     * `$consulta->campo('marca')` en vez de encadenar índices que revientan
     * cuando falta uno.
     */
    public function campo(string $nombre): ?string
    {
        $valor = $this->vehiculo()[$nombre] ?? null;

        return is_scalar($valor) ? (string) $valor : null;
    }

    /**
     * ¿Se puede confiar en esta respuesta para tomar una decisión?
     *
     * Una respuesta parcial trae datos buenos pero incompletos: sirve para
     * mostrar, no para concluir que un vehículo está libre de gravámenes.
     */
    public function esCompleta(): bool
    {
        return $this->completitud === 'completa';
    }
}
