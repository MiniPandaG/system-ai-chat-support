<?php

declare(strict_types=1);

/*
 * IA (M11). Los modelos de TikaroChat se llaman con una llave por proveedor que vive solo en variables de entorno. Un
 * proveedor sin llave no aparece en el catálogo. Las direcciones de los proveedores son fijas y salen de aquí: ninguna
 * persona, empresa ni mensaje puede cambiarlas (sin SSRF). «fake» solo existe para pruebas y la aplicación se niega a
 * arrancar con él en producción.
 */
return [
    'fake' => (bool) env('AI_FAKE', false),

    /*
    | Proveedores. `driver`: «openai» sirve para todo el que habla el formato de OpenAI (OpenAI, DeepSeek, Gemini);
    | «anthropic» es la API de mensajes de Anthropic. `region` es lo que se le dice al administrador sobre dónde se procesan
    | los textos (Ley 1581: transferencia internacional). `extra_body` son campos fijos que cada proveedor necesita.
    */
    'providers' => [
        'deepseek' => [
            'label' => 'DeepSeek',
            'driver' => 'openai',
            'base_url' => 'https://api.deepseek.com',
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'region' => 'China (servidores de DeepSeek)',
            'max_tokens_param' => 'max_tokens',
            'json_mode' => true,
            // Sin cadena de pensamiento: es más rápido, más barato y las respuestas son cortas y con formato fijo.
            'extra_body' => ['thinking' => ['type' => 'disabled']],
        ],
        'anthropic' => [
            'label' => 'Claude (Anthropic)',
            'driver' => 'anthropic',
            'base_url' => 'https://api.anthropic.com',
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'region' => 'Estados Unidos (servidores de Anthropic)',
        ],
        'gemini' => [
            'label' => 'Gemini (Google)',
            'driver' => 'openai',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'api_key' => env('GEMINI_API_KEY', ''),
            'region' => 'Estados Unidos (servidores de Google)',
            'max_tokens_param' => 'max_tokens',
            'json_mode' => true,
            'extra_body' => [],
        ],
        'openai' => [
            'label' => 'OpenAI',
            'driver' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => env('OPENAI_API_KEY', ''),
            'region' => 'Estados Unidos (servidores de OpenAI)',
            'max_tokens_param' => 'max_completion_tokens',
            'json_mode' => true,
            'extra_body' => [],
        ],
    ],

    /*
    | Catálogo (RIA-08, RIA-09): dos modelos por proveedor, uno «fast» (rápido) y uno «precise» (preciso). Los precios son
    | dólares por millón de tokens, sin comillas de moneda, y se revisan cada mes. Un modelo sin precio verificado no entra:
    | sin precio no se puede cuidar el gasto. Los de OpenAI no están todavía: sus modelos actuales y sus precios se confirman
    | cuando haya una llave con la que comprobarlos.
    |
    | Precios tomados de cada proveedor el 2026-11-03. DeepSeek: tarifa en hora pico, cuando no hay caché (la más alta).
    | Gemini 3.8 Flash: la tarifa desde el 2027-01-01 (hasta el 31 de diciembre de 2026 es la mitad). Claude Haiku 5.5: hasta
    | 100 000 tokens de entrada; el motor nunca envía más que eso.
    */
    'catalog' => [
        'deepseek-fast' => ['provider' => 'deepseek', 'model' => 'deepseek-flash', 'tier' => 'fast', 'label' => 'DeepSeek Flash', 'input' => '0.30', 'output' => '1.20'],
        'deepseek-precise' => ['provider' => 'deepseek', 'model' => 'deepseek-v4-pro', 'tier' => 'precise', 'label' => 'DeepSeek V4 Pro', 'input' => '1.32', 'output' => '3.96'],
        'claude-fast' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'tier' => 'fast', 'label' => 'Claude Haiku 5.5', 'input' => '0.10', 'output' => '0.50', 'effort' => 'low'],
        'claude-precise' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'tier' => 'precise', 'label' => 'Claude Sonnet 5.5', 'input' => '2.00', 'output' => '10.00', 'effort' => 'medium'],
        'gemini-fast' => ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'tier' => 'fast', 'label' => 'Gemini 3.5 Flash-Lite', 'input' => '0.30', 'output' => '2.50'],
        'gemini-precise' => ['provider' => 'gemini', 'model' => 'gemini-3.8-flash', 'tier' => 'precise', 'label' => 'Gemini 3.8 Flash', 'input' => '1.50', 'output' => '7.50'],
    ],

    /* Una respuesta típica, para estimar el costo que se le muestra al administrador (RIA-09). */
    'estimate' => ['input_tokens' => 2500, 'output_tokens' => 350],

    'http' => [
        'connect_timeout' => 5,
        'timeout' => 60,
        /* Una respuesta más grande que esto se descarta. */
        'max_response_bytes' => 1048576,
    ],

    /* Topes de cada llamada, para cuidar el costo: el modelo no recibe ni produce más que esto. */
    'max_output_tokens' => 1500,
    'max_input_chars' => 24000,

    'reply' => [
        /* Cuánto de la conversación ve el modelo: los últimos mensajes, cada uno recortado. */
        'history_messages' => 10,
        'history_chars' => 3000,
        /* Fragmentos de conocimiento que se le dan, y el parecido mínimo para que cuenten (0 a 1). */
        'knowledge_limit' => 5,
        'min_score' => 0.75,
        /* Una respuesta más larga que esto no se usa. */
        'max_answer_chars' => 4000,
        /* Espera antes de responder: si el cliente sigue escribiendo, se responde una sola vez a todo. */
        'debounce_seconds' => 4,
    ],

    /* La confianza (0 a 100) la calcula el código con el parecido de los fragmentos citados: por debajo de `floor` es 0 y desde `ceil` es 100. */
    'confidence' => [
        /* Calibrado con embeddings multilingual-e5-small y respuestas de DeepSeek (preguntas con respuesta: 0,85 a 0,90; sin respuesta: hasta 0,80). Revisar con datos reales de cada instalación. */
        'floor' => (float) env('AI_CONFIDENCE_FLOOR', 0.77),
        'ceil' => (float) env('AI_CONFIDENCE_CEIL', 0.86),
        /* Una respuesta que sale solo del contexto de la empresa, sin fragmentos, no tiene parecido que medir. */
        'context_only' => 75,
        /* Si el propio modelo pidió una persona, la confianza no pasa de esto. */
        'needs_human_cap' => 40,
    ],

    /* Topes de gasto: los valores de fábrica de cada empresa y lo máximo que una empresa puede fijar, en millonésimas de dólar. */
    'limits' => [
        'daily_spend_micro' => 2_000_000,
        'monthly_spend_micro' => 30_000_000,
        'max_daily_spend_micro' => 50_000_000,
        'max_monthly_spend_micro' => 500_000_000,
    ],

    /* Zonas horarias que una empresa puede elegir para el horario de la IA. */
    'timezones' => [
        'America/Bogota', 'America/Lima', 'America/Mexico_City', 'America/Guatemala', 'America/Costa_Rica', 'America/Panama', 'America/Caracas',
        'America/Santo_Domingo', 'America/Guayaquil', 'America/La_Paz', 'America/Santiago', 'America/Asuncion', 'America/Montevideo',
        'America/Argentina/Buenos_Aires', 'America/Sao_Paulo', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'Europe/Madrid', 'UTC',
    ],

    'escalation' => [
        'max_topics' => 20,
        'topic_length' => 40,
        'custom_tone_length' => 500,
        'summary_max_chars' => 1200,
        'summary_max_tokens' => 400,
    ],
];
