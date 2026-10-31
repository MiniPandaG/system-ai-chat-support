<?php

declare(strict_types=1);

/*
| Mensajes de límite de plan. Siempre dicen qué plan es, cuánto permite, cuánto se usa y qué hacer; la última frase
| depende de que aún no hay cobro: el cambio lo hace el equipo de TikaroChat.
*/
return [
    'page' => [
        'title' => 'Plan',
        'current' => 'Tu plan actual',
        'detail' => 'Lo que incluye tu plan y cuánto estás usando. Por ahora los cambios de plan los hace el equipo de TikaroChat: pídelo cuando lo necesites.',
        'usage' => ':used de :max',
        'unlimited' => 'Sin límite',
        'remaining' => 'Te quedan :remaining',
        'none_left' => 'No te queda ninguno',
        'over' => 'Superas el límite: usas :used y tu plan permite :max. Nadie pierde el acceso, pero no se pueden añadir más hasta que el plan lo permita.',
        'full' => 'Llegaste al límite de tu plan.',
        'monthly' => 'Se renueva el primer día de cada mes.',
        'included' => 'Incluye hasta :max',
        'not_available' => 'Todavía no disponible en tu cuenta: se medirá cuando exista.',
        'members' => '{0} Ningún miembro activo|{1} 1 miembro activo|[2,*] :count miembros activos',
        'invitations' => '{0} sin invitaciones pendientes|{1} 1 invitación pendiente|[2,*] :count invitaciones pendientes',
        'labels' => [
            'agents' => 'Puestos de agente',
            'channels' => 'Canales',
            'tickets_per_month' => 'Tickets al mes',
            'ai_credits' => 'Créditos de IA al mes',
        ],
        'help' => [
            'agents' => 'Cuentan los administradores y agentes activos y las invitaciones pendientes. Los observadores no ocupan puesto.',
            'channels' => 'Los canales por donde te escriben tus clientes: chat web, correo y otros.',
            'tickets_per_month' => 'Las conversaciones nuevas que se abren cada mes.',
            'ai_credits' => 'Lo que puede usar la inteligencia artificial cada mes.',
        ],
    ],

    'reached' => [
        'agents' => '{0} Tu plan :plan no incluye puestos de agente. Pide un cambio de plan al equipo de TikaroChat.|{1} Tu plan :plan permite 1 puesto de agente y ya está en uso. Los observadores no ocupan puesto. Para añadir otro agente o administrador, pide un cambio de plan al equipo de TikaroChat.|[2,*] Tu plan :plan permite :max puestos de agente y ya usas :used. Los observadores no ocupan puesto. Para añadir más agentes o administradores, pide un cambio de plan al equipo de TikaroChat.',
        'agents_some' => '{1} Tu plan :plan solo tiene 1 puesto de agente libre y quieres añadir :adding. Los observadores no ocupan puesto. Pide un cambio de plan al equipo de TikaroChat o invita a menos personas.|[2,*] Tu plan :plan solo tiene :remaining puestos de agente libres y quieres añadir :adding. Los observadores no ocupan puesto. Pide un cambio de plan al equipo de TikaroChat o invita a menos personas.',
        'channels' => '{1} Tu plan :plan permite 1 canal y ya lo usas. Para añadir otro, pide un cambio de plan al equipo de TikaroChat.|[2,*] Tu plan :plan permite :max canales y ya usas :used. Para añadir más, pide un cambio de plan al equipo de TikaroChat.',
        'channels_some' => 'Tu plan :plan solo tiene :remaining canales libres y quieres añadir :adding.',
        'tickets_per_month' => 'Tu plan :plan permite :max tickets al mes y ya usaste :used. El límite se renueva el primer día del mes; también puedes pedir un cambio de plan al equipo de TikaroChat.',
        'tickets_per_month_some' => 'Tu plan :plan solo tiene :remaining tickets libres este mes.',
        'ai_credits' => 'Tu plan :plan incluye :max créditos de IA al mes y ya usaste :used. Los créditos se renuevan el primer día del mes; también puedes pedir un cambio de plan al equipo de TikaroChat.',
        'ai_credits_some' => 'Tu plan :plan solo tiene :remaining créditos de IA libres este mes.',
        'accept' => 'Esta empresa ya alcanzó el límite de personas de su plan. Pide al administrador de tu empresa que lo revise.',
    ],
];
