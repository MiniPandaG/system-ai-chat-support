<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Files\FilePurpose;
use App\Files\FileStore;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\Images;

beforeEach(function (): void {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->forTenant($this->tenant, Role::Agent)->create();
    $this->file = app(TenantContext::class)->run($this->tenant, fn (): StoredFile => app(FileStore::class)->storeImage(Images::upload('logo.png', Images::png()), FilePurpose::Logo, $this->owner));
    $this->url = app(FileStore::class)->temporaryUrl($this->file);
});

it('un miembro de la empresa descarga el archivo con la URL firmada', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toBe((string) Storage::disk('local')->get($this->file->path));
});

it('la URL caduca, a más tardar al doble del tiempo configurado', function (): void {
    $this->travel(11)->minutes();

    $this->actingAs($this->owner)->get($this->url)->assertForbidden();
});

it('la URL no sirve si se altera la firma, el identificador o se quita', function (): void {
    $this->actingAs($this->owner);

    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $this->url))->assertForbidden();
    // Con otro identificador la ruta ni siquiera encuentra el archivo: 404 en vez de 403, y en ambos casos no se sirve.
    expect($this->get(str_replace($this->file->id, strrev($this->file->id), $this->url))->getStatusCode())->toBeIn([403, 404]);
    $this->get(route('files.show', ['file' => $this->file->id]))->assertForbidden();
});

it('sin sesión se pide iniciar sesión, aunque la URL esté firmada', function (): void {
    $this->get($this->url)->assertRedirect('/login');
});

it('otra empresa recibe 404 aunque tenga una URL válida', function (): void {
    $intruder = User::factory()->forTenant(Tenant::factory()->create(), Role::Admin)->create();

    $this->assertOtherTenantGets404($intruder, 'GET', $this->url);
});

it('el personal de la plataforma tampoco descarga archivos de una empresa', function (): void {
    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($staff)->get($this->url)->assertNotFound();
});

it('un miembro desactivado no descarga', function (): void {
    $this->owner->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($this->owner->fresh() ?? $this->owner)->get($this->url)->assertRedirect(route('login'));
});

it('un identificador mal formado o inexistente da 404', function (string $id): void {
    $url = URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['file' => $id]);

    $this->actingAs($this->owner)->get($url)->assertNotFound();
})->with([
    'inexistente' => [str_repeat('0', 26)],
    'recorrido de rutas' => ['..%2F..%2F.env'],
    'corto' => ['abc'],
]);

it('si el archivo falta en el disco responde 404 y no un error interno', function (): void {
    Storage::disk('local')->delete($this->file->path);

    $this->actingAs($this->owner)->get($this->url)->assertNotFound();
});
