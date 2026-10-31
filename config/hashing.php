<?php

declare(strict_types=1);

return [

    'driver' => 'argon2id',

    'argon' => [
        'memory' => (int) env('HASH_ARGON_MEMORY', 65536),
        'threads' => (int) env('HASH_ARGON_THREADS', 1),
        'time' => (int) env('HASH_ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
