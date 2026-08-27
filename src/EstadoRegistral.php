<?php

declare(strict_types=1);

namespace Egobytes\Rnp;

/**
 * Gravámenes, anotaciones, infracciones o levantamientos.
 *
 * `posee` tiene tres estados y no dos. `null` significa que el Registro no lo
 * dijo, y tratarlo como un "no" equivale a afirmar que un vehículo está libre
 * cuando en realidad no se sabe. Por eso no hay un método `posee(): bool`:
 * obligar a esa firma sería obligar a mentir.
 */
final readonly class EstadoRegistral
{
    private function __construct(
        public ?bool $posee,
        /** @var array<int, array<string, string|null>> */
        public array $items,
        public ?string $nota,
    ) {}

    public static function desdeArray(?array $datos): self
    {
        return new self(
            posee: isset($datos['posee']) ? (bool) $datos['posee'] : null,
            items: $datos['items'] ?? [],
            nota: $datos['nota'] ?? null,
        );
    }

    /** El Registro afirmó que sí. */
    public function confirmado(): bool
    {
        return $this->posee === true;
    }

    /** El Registro afirmó que no. */
    public function descartado(): bool
    {
        return $this->posee === false;
    }

    /** El Registro no lo dijo: no se puede concluir nada. */
    public function indeterminado(): bool
    {
        return $this->posee === null;
    }
}
