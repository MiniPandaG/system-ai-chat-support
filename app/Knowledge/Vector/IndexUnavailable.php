<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use RuntimeException;

/** La base vectorial no respondió con algo utilizable. El mensaje es genérico: nunca lleva direcciones ni detalles. */
final class IndexUnavailable extends RuntimeException {}
