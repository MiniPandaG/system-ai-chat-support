<?php

declare(strict_types=1);

// Prepara una empresa de prueba de carga con tickets de ejemplo, un agente y una llave de API. Solo para máquinas de
// desarrollo o de pruebas: se niega a correr en producción. La empresa se quita después con tenant:purge (run.sh lo hace).
// Imprime en una sola línea JSON lo que necesita run.sh (el agente y la llave son de esta empresa inventada).

use App\Api\ApiKeys;
use App\Api\ApiPermission;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Este script crea datos de ejemplo y no corre en producción.\n");
    exit(1);
}

$total = (int) ($argv[1] ?? 10000);
$password = Str::password(24);
$tenant = Tenant::factory()->onPlan('negocio')->create(['name' => 'Empresa de carga']);
// Varios agentes, como en un equipo real: el límite de peticiones es por persona, y diez navegadores con una sola cuenta no son un día normal.
$agents = [];

foreach (range(1, 10) as $i) {
    $agent = User::factory()->forTenant($tenant, Role::Agent)->create(['name' => "Agente de carga {$i}", 'email' => 'carga'.$i.'-'.Str::lower(Str::random(6)).'@ejemplo.test', 'password' => Hash::make($password)]);
    $agent->forceFill(['email_verified_at' => now()])->save();
    $agents[] = ['email' => $agent->email, 'password' => $password];
}

$admin = User::factory()->forTenant($tenant, Role::Admin)->create();

$key = $app->make(TenantContext::class)->run($tenant, fn (): string => $app->make(ApiKeys::class)->create($admin, $tenant, 'carga', ApiPermission::Admin, null)['plain']);

$customers = [];

foreach (range(1, 200) as $i) {
    $customers[] = ['tenant_id' => $tenant->id, 'name' => "Cliente {$i}", 'email' => "cliente{$i}@ejemplo.test", 'created_at' => now(), 'updated_at' => now()];
}

DB::table('customers')->insert($customers);
$customerIds = DB::table('customers')->where('tenant_id', $tenant->id)->pluck('id')->all();
$statuses = ['open', 'open', 'waiting_customer', 'resolved', 'closed'];
$priorities = ['low', 'normal', 'normal', 'high', 'urgent'];

foreach (array_chunk(range(1, $total), 1000) as $chunk) {
    $tickets = [];

    foreach ($chunk as $n) {
        $created = now()->subMinutes($n * 4);
        $tickets[] = [
            'tenant_id' => $tenant->id, 'number' => $n, 'customer_id' => $customerIds[$n % 200], 'subject' => "Consulta de ejemplo {$n} sobre un pedido",
            'status' => $statuses[$n % 5], 'priority' => $priorities[$n % 5], 'channel' => 'panel', 'assigned_to' => $n % 3 === 0 ? $agent->id : null,
            'created_by' => $admin->id, 'last_message_at' => $created, 'created_at' => $created, 'updated_at' => $created,
        ];
    }

    DB::table('tickets')->insert($tickets);
}

$ids = DB::table('tickets')->where('tenant_id', $tenant->id)->pluck('id')->all();

foreach (array_chunk($ids, 1000) as $chunk) {
    $messages = [];

    foreach ($chunk as $ticketId) {
        $messages[] = ['tenant_id' => $tenant->id, 'ticket_id' => $ticketId, 'author' => 'customer', 'body' => 'Hola, mi pedido todavía no ha llegado y quisiera saber dónde está.', 'created_at' => now(), 'updated_at' => now()];
    }

    DB::table('ticket_messages')->insert($messages);
}

$tenant->forceFill(['last_ticket_number' => $total])->save();

echo json_encode(['tenant' => $tenant->id, 'agents' => $agents, 'api_key' => $key, 'tickets' => $total, 'first_ticket' => $ids[0]], JSON_THROW_ON_ERROR)."\n";
