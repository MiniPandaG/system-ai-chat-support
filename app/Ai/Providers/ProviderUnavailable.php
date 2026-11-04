<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use RuntimeException;

/**
 * Una llamada a un proveedor no dio algo utilizable. Lleva solo el motivo de la lista cerrada y un mensaje fijo: nunca
 * la respuesta del proveedor, la dirección ni la llave.
 */
final class ProviderUnavailable extends RuntimeException
{
    public function __construct(public readonly ProviderError $error)
    {
        parent::__construct((string) __('ai.errors.'.$error->value));
    }
}
