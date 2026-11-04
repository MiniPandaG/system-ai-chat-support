<?php

declare(strict_types=1);

// Crea la empresa de ejemplo para las pruebas de usabilidad (Zapatería Ejemplo) con datos inventados. Se niega a
// correr en producción. Modos: crear (por defecto), otp (el código actual del administrador), empresa (su número) y olvidar (quita el estado).
// Las contraseñas se imprimen una sola vez al crear, para quien conduce la sesión; el estado queda en storage/app/private.

use App\Actions\UpdateTicket;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WidgetOrigin;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Este script crea datos de ejemplo y no corre en producción.\n");
    exit(1);
}

$mode = $argv[1] ?? 'crear';
$stateFile = storage_path('app/private/demo-state.json');

if ($mode === 'otp') {
    $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
    echo (new Google2FA)->getCurrentOtp($state['secret'])."\n";

    return;
}

if ($mode === 'empresa') {
    $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
    echo $state['tenant']."\n";

    return;
}

if ($mode === 'olvidar') {
    unlink($stateFile);

    return;
}

if (is_file($stateFile)) {
    fwrite(STDERR, "Ya hay una empresa de demostración creada. Bórrala antes con scripts/demo/teardown.sh.\n");
    exit(1);
}

config(['queue.default' => 'sync']);

$tenant = Tenant::factory()->onPlan('negocio')->create(['name' => 'Zapatería Ejemplo']);
$secret = (new Google2FA)->generateSecretKey(32);
$adminPassword = Str::password(20);
$agentPassword = Str::password(20);

$admin = User::factory()->forTenant($tenant, Role::Admin)->create(['name' => 'Ana Torres', 'email' => 'ana.'.Str::lower(Str::random(5)).'@zapateria-ejemplo.test', 'password' => Hash::make($adminPassword)]);
$admin->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['sin-uso'])), 'two_factor_confirmed_at' => now(), 'email_verified_at' => now()])->save();
$agent = User::factory()->forTenant($tenant, Role::Agent)->create(['name' => 'Luis Pérez', 'email' => 'luis.'.Str::lower(Str::random(5)).'@zapateria-ejemplo.test', 'password' => Hash::make($agentPassword)]);
$agent->forceFill(['email_verified_at' => now()])->save();

$app->make(TenantContext::class)->run($tenant, function () use ($tenant, $admin, $agent): void {
    $origin = new WidgetOrigin;
    $origin->origin = 'http://localhost:9090';
    $origin->save();

    $rows = [
        ['Mis zapatos llegaron con la suela despegada', 'Marisol Quintero', TicketPriority::Urgent, null, 'Hola, compré unos zapatos hace tres días y llegaron con la suela despegada. Necesito un cambio.'],
        ['No me ha llegado el pedido 4521', 'Camilo Restrepo', TicketPriority::Urgent, null, 'Hice el pedido 4521 hace diez días y todavía no aparece el envío.'],
        ['¿Hacen cambios de talla?', 'Valentina Ospina', TicketPriority::Normal, null, 'Me quedaron grandes. ¿Puedo cambiarlos por una talla menos?'],
        ['Factura con otro nombre', 'Andrés Mejía', TicketPriority::Low, null, 'Necesito que la factura salga a nombre de mi empresa.'],
        ['Cobro doble en la tarjeta', 'Daniela Ruiz', TicketPriority::High, $agent, 'Me cobraron dos veces el mismo pedido, adjunto el extracto.'],
        ['Quiero cambiar la dirección de entrega', 'Julián Gómez', TicketPriority::Normal, $agent, 'Me mudé y necesito cambiar la dirección antes de que salga el pedido.'],
        ['Horario de la tienda del centro', 'Paula Herrera', TicketPriority::Low, $admin, '¿Hasta qué hora atienden los sábados?'],
        ['Descuento por compra al por mayor', 'Sergio Londoño', TicketPriority::Normal, null, 'Quiero comprar 30 pares para mi equipo. ¿Hay descuento?'],
    ];

    foreach ($rows as $index => [$subject, $name, $priority, $assignee, $body]) {
        $customer = Customer::factory()->create(['name' => $name, 'email' => Str::of($name)->lower()->ascii()->replace(' ', '.').'@cliente-ejemplo.test']);
        $number = $index + 1;
        $ticket = Ticket::factory()->forCustomer($customer)->create(['number' => $number, 'subject' => $subject, 'channel' => Channel::Panel, 'priority' => $priority, 'status' => TicketStatus::Open, 'assigned_to' => $assignee?->id, 'created_by' => $admin->id, 'last_message_at' => now()->subHours(9 - $index)]);
        $message = new TicketMessage(['body' => $body]);
        $message->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Customer])->save();
    }

    $tenant->forceFill(['last_ticket_number' => count($rows)])->save();

    // Un ticket ya atendido, con correo del cliente: al resolverlo sale la encuesta a Mailpit (tarea T8 del cliente final).
    $customer = Customer::factory()->create(['name' => 'Marisol Quintero', 'email' => 'marisol.encuesta@cliente-ejemplo.test']);
    $ticket = Ticket::factory()->forCustomer($customer)->create(['number' => count($rows) + 1, 'subject' => 'Cambio de zapatos confirmado', 'channel' => Channel::Panel, 'status' => TicketStatus::Open, 'created_by' => $admin->id]);
    $message = new TicketMessage(['body' => 'Gracias, ya recibí el cambio.']);
    $message->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Customer])->save();
    app(UpdateTicket::class)($agent, $ticket, ['status' => TicketStatus::Resolved]);
    $tenant->forceFill(['last_ticket_number' => count($rows) + 1])->save();
});

file_put_contents($stateFile, json_encode(['tenant' => $tenant->id, 'secret' => $secret], JSON_THROW_ON_ERROR));
chmod($stateFile, 0600);

echo json_encode(['tenant' => $tenant->id, 'admin_email' => $admin->email, 'admin_password' => $adminPassword, 'agent_email' => $agent->email, 'agent_password' => $agentPassword, 'widget_key' => $tenant->widget_key], JSON_THROW_ON_ERROR)."\n";
