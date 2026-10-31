<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Crea una empresa nueva junto con su primer administrador, todo o nada.
 */
class RegisterTenant
{
    public function __invoke(string $companyName, string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($companyName, $name, $email, $password): User {
            $tenant = Tenant::query()->create(['name' => $companyName]);

            $admin = new User(['name' => $name, 'email' => mb_strtolower($email), 'password' => $password]);
            // La empresa y el rol no son rellenables: solo este flujo y la aceptación de invitaciones los fijan.
            $admin->forceFill(['tenant_id' => $tenant->getKey(), 'role' => Role::Admin])->save();

            return $admin;
        });
    }
}
