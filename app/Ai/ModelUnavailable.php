<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;

/** El modelo pedido no existe en el catálogo o su proveedor no está configurado. */
final class ModelUnavailable extends RuntimeException {}
