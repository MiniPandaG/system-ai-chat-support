<?php

declare(strict_types=1);

arch('no hay funciones de depuración ni de ejecución de código')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'])
    ->not->toBeUsed();

arch('no se usa unserialize sobre datos externos')
    ->expect('unserialize')
    ->not->toBeUsed();

arch('no se usan algoritmos débiles para seguridad')
    ->expect(['md5', 'sha1', 'crc32', 'rand', 'mt_rand', 'uniqid'])
    ->not->toBeUsed();

arch('todo el código de la aplicación declara strict_types')
    ->expect('App')
    ->toUseStrictTypes();
