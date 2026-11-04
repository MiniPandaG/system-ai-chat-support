<?php

declare(strict_types=1);

namespace App\Ai;

/** Un modelo del catálogo que la empresa puede elegir (RIA-08). */
final readonly class CatalogModel
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $providerLabel,
        /** Dónde se procesan los textos, tal como se le dice al administrador. */
        public string $region,
        public string $model,
        /** «fast» o «precise». */
        public string $tier,
        public string $label,
        /** Millonésimas de dólar por millón de tokens de entrada. */
        public int $inputMicro,
        public int $outputMicro,
        public ?string $effort,
        /** ¿Su proveedor tiene llave y se puede usar ahora? */
        public bool $available,
    ) {}
}
