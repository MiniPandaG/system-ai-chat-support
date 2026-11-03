<?php

declare(strict_types=1);

namespace App\Knowledge;

use RuntimeException;

/** No se pudo sacar el texto de un documento. Lleva solo el motivo de la lista cerrada, nunca el error técnico. */
final class ExtractionFailed extends RuntimeException
{
    public function __construct(public readonly DocumentFailure $failure)
    {
        parent::__construct($failure->value);
    }
}
