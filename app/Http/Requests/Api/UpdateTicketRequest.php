<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\UpdateTicketRequest as PanelUpdateTicketRequest;

/** Cambiar el estado, la prioridad o quien atiende por la API: las mismas reglas que el panel; la llave ya trae su permiso. */
class UpdateTicketRequest extends PanelUpdateTicketRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
