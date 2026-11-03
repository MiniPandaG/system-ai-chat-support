<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use RuntimeException;

/** El servicio de embeddings no respondió con algo utilizable. El mensaje es genérico: nunca lleva direcciones ni detalles. */
final class EmbeddingUnavailable extends RuntimeException {}
