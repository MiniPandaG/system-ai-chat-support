<?php

declare(strict_types=1);

namespace App\Ai\Reply;

use RuntimeException;

/** Lo que devolvió el modelo no se puede usar. El mensaje es interno y nunca se muestra ni se guarda. */
final class InvalidReply extends RuntimeException {}
