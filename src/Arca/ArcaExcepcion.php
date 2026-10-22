<?php
declare(strict_types=1);

namespace Facturador\Arca;

/**
 * Error de ARCA ya traducido: categoría + mensaje para mostrarle al cliente.
 * La categoría es la que decide qué hace el Procesador (reintentar, pausar, seguir).
 */
final class ArcaExcepcion extends \RuntimeException
{
    public function __construct(
        public readonly string $categoria,
        string $mensaje,
        public readonly ?string $codigo = null,
        public readonly ?string $mensajeArca = null,
        ?\Throwable $previa = null,
    ) {
        parent::__construct($mensaje, 0, $previa);
    }

    public function esTecnico(): bool
    {
        return $this->categoria === Errores::TECNICO;
    }

    /** Línea para la bitácora: categoría, código y mensaje original. */
    public function paraLog(): string
    {
        return $this->categoria
            . ($this->codigo !== null ? " [{$this->codigo}]" : '')
            . ': ' . ($this->mensajeArca ?? $this->getMessage());
    }
}
