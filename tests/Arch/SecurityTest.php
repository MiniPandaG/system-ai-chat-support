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

// Las notas internas son solo del equipo. Si algún día un canal pensado para el cliente (widget, correo, API pública)
// necesita leer mensajes, no debe poder tocar este modelo: añadir una clase a esta lista exige que una persona lo revise.
arch('solo la conversación del equipo y la acción que las crea usan las notas internas')
    ->expect('App\Models\TicketNote')
    ->toOnlyBeUsedIn([
        'App\Models\Ticket',
        'App\Actions\AddTicketNote',
        'App\Tickets\Thread',
        'Database\Factories\TicketNoteFactory',
    ]);

// Un archivo de un ticket solo se guarda con AttachmentStore y solo se marca limpio con la revisión del antivirus.
arch('solo el almacén, la revisión y la conversación usan los adjuntos de tickets')
    ->expect('App\Models\TicketAttachment')
    ->toOnlyBeUsedIn([
        'App\Models\TicketMessage',
        'App\Attachments\AttachmentStore',
        'App\Actions\AddTicketMessage',
        'App\Jobs\ScanAttachment',
        'App\Console\Commands\RescanAttachments',
        'App\Http\Controllers\AttachmentController',
        'App\Tickets\Thread',
        'Database\Factories\TicketAttachmentFactory',
    ]);

// El antivirus de mentira da por limpio casi todo: solo el proveedor de servicios decide si se usa, y no en producción.
arch('el antivirus de pruebas solo lo conoce el proveedor de servicios')
    ->expect('App\Attachments\FakeScanner')
    ->toOnlyBeUsedIn(['App\Providers\AppServiceProvider']);

// Tiempo real: ningún canal es público. Todo lo que se emite va por canales privados, que exigen autorización.
arch('ninguna parte de la aplicación usa canales públicos de tiempo real')
    ->expect('App')
    ->not->toUse(['Illuminate\Broadcasting\Channel', 'Illuminate\Broadcasting\PresenceChannel']);
