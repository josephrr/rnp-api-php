<?php

declare(strict_types=1);

namespace Egobytes\Rnp;

/**
 * Las consultas que le quedan, tal como quedaron tras esta llamada.
 *
 * El API lo calcula después de cerrar el cobro, así que este número siempre
 * corresponde a lo que de verdad se descontó.
 */
final readonly class Saldo
{
    private function __construct(
        public int $plan,
        public int $creditos,
        public int $prueba,
        public int $total,
        public ?string $renuevaEn,
        public ?string $creditoVenceEn,
        /** Las cuentas exentas no consumen: `total` no significa nada en ellas. */
        public bool $exento,
    ) {}

    public static function desdeArray(array $datos): self
    {
        return new self(
            plan: (int) ($datos['plan'] ?? 0),
            creditos: (int) ($datos['creditos'] ?? 0),
            prueba: (int) ($datos['prueba'] ?? 0),
            total: (int) ($datos['total'] ?? 0),
            renuevaEn: $datos['renueva_en'] ?? null,
            creditoVenceEn: $datos['credito_vence_en'] ?? null,
            exento: (bool) ($datos['exento'] ?? false),
        );
    }

    /** Para avisar antes de quedarse sin nada a mitad de un proceso por lotes. */
    public function seEstaAcabando(int $umbral = 10): bool
    {
        return !$this->exento && $this->total <= $umbral;
    }
}
