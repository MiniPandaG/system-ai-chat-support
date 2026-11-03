<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\StoreTicketRequest as PanelStoreTicketRequest;
use App\Tickets\TicketPriority;
use Illuminate\Validation\Rule;

/**
 * Crear un ticket por la API. Las mismas reglas que el panel (asunto de una línea, descripción de texto plano, el
 * cliente y quien atiende deben ser de la empresa), con dos diferencias: la prioridad es opcional (normal) y un correo que
 * ya es de un cliente de la empresa no se rechaza, se reutiliza ese cliente.
 */
class StoreTicketRequest extends PanelStoreTicketRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['priority'] = ['nullable', 'string', Rule::enum(TicketPriority::class)];
        $rules['customer_email'] = ['nullable', 'email', 'max:255'];

        return $rules;
    }
}
