<?php

declare(strict_types=1);

namespace App\Ai;

/** Cuánto se enmascara de un texto antes de enviarlo a un proveedor externo (RS-15). */
enum MaskLevel: string
{
    /** Texto fijo del código: no se toca. */
    case None = 'none';

    /** Texto de la empresa (contexto, artículos): solo lo sensible (tarjetas, documentos, secretos). Su teléfono o correo de contacto sí debe poder citarse. */
    case Company = 'company';

    /** Texto de un cliente: además, sus correos y teléfonos. */
    case Customer = 'customer';
}
