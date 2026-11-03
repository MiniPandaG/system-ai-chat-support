<?php

declare(strict_types=1);

namespace App\Mail;

/** Para qué es un correo: el aviso al cliente de que el equipo respondió, la confirmación de ticket recibido o una respuesta del cliente. */
enum MailKind: string
{
    case Reply = 'reply';
    case Received = 'received';
    case Customer = 'customer';
}
