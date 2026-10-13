<?php

declare(strict_types=1);

namespace Egobytes\Rnp;

/**
 * La respuesta de consultar solo el vencimiento de Dekra.
 *
 * No trae vehículo, ni saldo, ni completitud: no se le preguntó al Registro
 * y no se descontó nada. `datos` en null significa que Dekra no publicó
 * fecha para esa placa, no que la consulta haya fallado.
 */
final readonly class ConsultaDekra
{
    private function __construct(
        /** @var array<string, mixed>|null fecha, placa y vigente, o null. */
        public ?array $datos,
        /** Siempre false: esta consulta no descuenta. */
        public bool $cobrada,
        public ?string $requestId,
        public ?string $consultadoEn,
    ) {}

    public static function desdeArray(array $cuerpo): self
    {
        $datos = $cuerpo['datos'] ?? null;

        return new self(
            datos: is_array($datos) ? $datos : null,
            cobrada: (bool) ($cuerpo['cobrada'] ?? false),
            requestId: is_string($cuerpo['request_id'] ?? null) ? $cuerpo['request_id'] : null,
            consultadoEn: is_string($cuerpo['consultado_en'] ?? null) ? $cuerpo['consultado_en'] : null,
        );
    }

    /**
     * true si la fecha es hoy o posterior, en hora de Costa Rica.
     *
     * null cuando no hay fecha, o cuando la respuesta es anterior al campo
     * `vigente` y no lo trae. No se convierte esa ausencia en un false.
     */
    public function vigente(): ?bool
    {
        $valor = $this->datos['vigente'] ?? null;

        return is_bool($valor) ? $valor : null;
    }
}
