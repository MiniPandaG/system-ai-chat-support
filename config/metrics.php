<?php

declare(strict_types=1);

return [
    /* La zona horaria con la que se cortan «hoy» y los días de las gráficas. Una sola para todas las empresas por ahora. */
    'timezone' => env('METRICS_TIMEZONE', 'America/Bogota'),

    /* Lo más largo que puede ser el rango de fechas, en días. */
    'max_range_days' => 366,

    /* Cuántos tickets se leen como máximo para calcular una mediana: con más, se usan los más recientes y se avisa. */
    'max_rows' => 50000,

    'comments_per_page' => 15,
];
