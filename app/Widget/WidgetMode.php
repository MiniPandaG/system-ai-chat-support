<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * El modo con el que el widget se muestra en el sitio de la empresa. «Sitio» deja que lo decida la preferencia del
 * visitante (modo claro u oscuro de su sistema).
 */
enum WidgetMode: string
{
    case Light = 'claro';
    case Dark = 'oscuro';
    case Site = 'sitio';
}
