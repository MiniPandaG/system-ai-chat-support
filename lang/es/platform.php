<?php

declare(strict_types=1);

/*
| Panel de la plataforma: lo que ve el personal de GeckoChat para administrar empresas y planes. Nunca muestra
| tickets, mensajes ni datos de los clientes de una empresa; solo cifras de uso.
*/
return [
    'tenants' => [
        'title' => 'Empresas',
        'detail' => 'Las empresas que usan GeckoChat, con su plan y cuánto usan. Una empresa nueva se registra sola desde la página de registro; aquí cambias su plan.',
        'search_label' => 'Buscar una empresa por nombre',
        'search_placeholder' => 'Nombre de la empresa',
        'search_button' => 'Buscar',
        'clear_search' => 'Quitar búsqueda',
        'table_label' => 'Empresas registradas',
        'name' => 'Empresa',
        'plan' => 'Plan',
        'members' => 'Miembros activos',
        'agents' => 'Puestos de agente',
        'tickets' => 'Tickets este mes',
        'since' => 'Desde',
        'unlimited' => 'sin límite',
        'usage' => ':used de :max',
        'over' => 'Por encima del plan',
        'change_plan' => 'Cambiar el plan de :company',
        'confirm_title' => '¿Cambiar el plan de :company?',
        'confirm_detail' => ':company pasará del plan :from al plan :to. Nadie pierde el acceso, pero si usa más de lo que permite el nuevo plan no podrá añadir más hasta que baje. Queda registrado en la auditoría.',
        'confirm' => 'Cambiar plan',
        'confirming' => 'Cambiando…',
        'cancel' => 'Cancelar',
        'plan_changed' => ':company ahora tiene el plan :plan.',
        'plan_same' => ':company ya tenía el plan :plan. No se cambió nada.',
        'empty' => 'Todavía no hay empresas registradas.',
        'empty_search' => 'Ninguna empresa coincide con esa búsqueda.',
        'page' => 'Página :page de :last',
        'previous' => 'Anterior',
        'next' => 'Siguiente',
    ],

    'plans' => [
        'title' => 'Planes',
        'detail' => 'El catálogo de planes. Un campo de límite vacío significa sin límite. Cambiar los límites de un plan afecta a todas las empresas que lo tienen; nadie pierde el acceso, solo se bloquea añadir más si superan el nuevo tope.',
        'new_title' => 'Plan nuevo',
        'new_detail' => 'La clave identifica al plan para siempre: solo letras minúsculas, números y guiones, y no se puede cambiar después.',
        'slug' => 'Clave',
        'name' => 'Nombre',
        'active' => 'Disponible para asignar a empresas',
        'save' => 'Guardar cambios',
        'saving' => 'Guardando…',
        'create' => 'Crear plan',
        'creating' => 'Creando…',
        'make_default' => 'Usar para empresas nuevas',
        'making_default' => 'Aplicando…',
        'is_default' => 'Plan de las empresas nuevas',
        'inactive' => 'No disponible',
        'tenants_none' => 'Ninguna empresa lo usa',
        'tenants_one' => '1 empresa lo usa',
        'tenants_many' => ':count empresas lo usan',
        'limit_help' => 'Vacío = sin límite',
        'labels' => [
            'agents' => 'Puestos de agente',
            'channels' => 'Canales',
            'tickets_per_month' => 'Tickets al mes',
            'ai_credits' => 'Créditos de IA al mes',
        ],
        'created' => 'El plan :plan quedó creado.',
        'updated' => 'El plan :plan quedó guardado.',
        'default_set' => 'Las empresas nuevas empezarán con el plan :plan.',
    ],

    'errors' => [
        'plan_required' => 'Elige un plan.',
        'plan_unavailable' => 'Ese plan no existe o no está disponible.',
        'slug_format' => 'La clave solo admite letras minúsculas, números y guiones, sin empezar ni terminar con guion.',
        'slug_taken' => 'Ya existe un plan con esa clave.',
        'slug_fixed' => 'La clave de un plan no se puede cambiar.',
        'name_format' => 'El nombre no puede llevar caracteres de control.',
        'default_inactive' => 'Este es el plan de las empresas nuevas y no puede quedar no disponible. Elige antes otro plan para las empresas nuevas.',
        'default_needs_active' => 'Solo un plan disponible puede ser el de las empresas nuevas.',
    ],
];
