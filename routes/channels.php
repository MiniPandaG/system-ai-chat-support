<?php

declare(strict_types=1);

use App\Broadcasting\InboxChannel;
use App\Broadcasting\TicketChannel;
use Illuminate\Support\Facades\Broadcast;

// Todos los canales son privados y llevan la empresa en el nombre. Se autoriza con la sesión del panel (ver bootstrap/app.php).
Broadcast::channel('tenant.{tenantId}.inbox', InboxChannel::class);
Broadcast::channel('tenant.{tenantId}.tickets.{ticketId}', TicketChannel::class);
