<?php

declare(strict_types=1);

namespace Egobytes\Rnp;

use RuntimeException;

/**
 * Un error devuelto por el API.
 *
 * Se ramifica por `codigo`, nunca por el mensaje: el texto puede cambiar sin
 * aviso y el código no. `reintentable` y `cobrada` están aquí para que quien
 * integra no tenga que memorizar la tabla de la documentación.
 */
class RnpException extends RuntimeException
{
    /** Los únicos que cambian de resultado por volver a llamar. */
    public const REINTENTABLES = [
        'limite_excedido',
        'origen_no_disponible',
        'sin_capacidad',
        'tiempo_agotado',
    ];

    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $httpStatus = 0,
        /** Si esta consulta descontó del saldo. Solo `vehiculo_no_encontrado` lo hace. */
        public readonly bool $cobrada = false,
        /** Segundos a esperar. Solo llega en `limite_excedido`. */
        public readonly ?int $reintentarEn = null,
        /** Dónde recargar, cuando el problema es de saldo. */
        public readonly ?string $recargaUrl = null,
        /** Identificador de la consulta. Cítelo al reportar un problema. */
        public readonly ?string $requestId = null,
        /** El saldo tal como quedó. */
        public readonly ?Saldo $saldo = null,
    ) {
        parent::__construct($mensaje, $httpStatus);
    }

    public static function desdeRespuesta(array $cuerpo, int $httpStatus): self
    {
        $error = $cuerpo['error'] ?? [];

        return new self(
            codigo: $error['code'] ?? 'error_desconocido',
            mensaje: $error['message'] ?? 'La consulta no se pudo completar.',
            httpStatus: $httpStatus,
            cobrada: (bool) ($cuerpo['cobrada'] ?? false),
            reintentarEn: isset($error['reintentar_en']) ? (int) $error['reintentar_en'] : null,
            recargaUrl: $error['recarga_url'] ?? null,
            requestId: $error['request_id'] ?? $cuerpo['request_id'] ?? null,
            saldo: isset($cuerpo['saldo']) && is_array($cuerpo['saldo']) ? Saldo::desdeArray($cuerpo['saldo']) : null,
        );
    }

    /** El API no respondió: red caída, DNS, tiempo agotado del lado del cliente. */
    public static function sinRespuesta(string $detalle): self
    {
        return new self(
            codigo: 'sin_conexion',
            mensaje: 'No se pudo contactar al API: ' . $detalle,
        );
    }

    public function esReintentable(): bool
    {
        return in_array($this->codigo, self::REINTENTABLES, true)
            || $this->codigo === 'sin_conexion';
    }

    /** No hay vehículo con ese identificador. Es el único error que descuenta. */
    public function noEncontrado(): bool
    {
        return $this->codigo === 'vehiculo_no_encontrado';
    }

    /** Se acabó el saldo o la prueba: hay que comprar, no reintentar. */
    public function faltaSaldo(): bool
    {
        return in_array($this->codigo, ['saldo_agotado', 'prueba_finalizada'], true);
    }

    /** La clave no sirve: revocada, inválida o cuenta suspendida. */
    public function problemaDeCredencial(): bool
    {
        return in_array($this->codigo, ['credencial_invalida', 'credencial_revocada', 'cuenta_suspendida'], true);
    }
}
