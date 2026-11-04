<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;

/** Lo que se iba a enviar al modelo pasa del tope: no se llama (cuida el costo). */
final class InputTooLong extends RuntimeException {}
