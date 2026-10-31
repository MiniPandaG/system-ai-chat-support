<?php

declare(strict_types=1);

namespace App\Files;

/**
 * Para qué se subió un archivo. Cada módulo que guarde archivos añade su propósito aquí.
 */
enum FilePurpose: string
{
    case Logo = 'logo';
}
