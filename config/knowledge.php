<?php

declare(strict_types=1);

/*
 * Límites de la base de conocimiento por empresa (M10). Los planes todavía no miden conocimiento, así que los topes viven
 * aquí; todo lo que se sube a la base es contenido no confiable y estos números frenan el abuso de espacio y de cómputo.
 */
return [
    'articles' => [
        'max' => 500,
        'title_max' => 160,
        'body_max' => 50000,
        'category_max' => 40,
        'per_page' => 25,
    ],

    'documents' => [
        'max' => 100,
        'max_bytes' => 25 * 1024 * 1024,
        'max_pages' => 150,
        /* Caracteres de texto que puede tener un documento ya extraído. */
        'text_max' => 300000,
        'uploads_per_hour' => 10,
        'title_max' => 160,
        'category_max' => 40,
        'per_page' => 25,
        /* Memoria máxima, en bytes, que puede gastar el lector de PDF al descomprimir un contenido. */
        'decode_memory_bytes' => 64 * 1024 * 1024,
        /*
        | Extensión → tipos reales que puede tener el contenido (el tipo sale del contenido, nunca de lo que declara el
        | navegador). Solo texto y PDF: un DOCX es un ZIP con XML y no entra en esta fase.
        */
        'types' => [
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        ],
    ],

    /*
    | Embeddings: un modelo abierto servido dentro de la red de Docker (D-06), para que el texto de las empresas no salga
    | de la plataforma. «fake» solo existe para pruebas e integración continua y la aplicación se niega a arrancar con él
    | en producción. Los prefijos son los que pide el modelo multilingual-e5 para distinguir lo que se guarda de lo que se
    | pregunta; con otro modelo se cambian aquí.
    */
    'embeddings' => [
        'driver' => env('KNOWLEDGE_EMBEDDER', 'tei'),
        'url' => env('EMBEDDINGS_URL', 'http://embeddings:80'),
        'model' => 'intfloat/multilingual-e5-small',
        'dimensions' => 384,
        'batch' => 16,
        'timeout' => 30,
        'prefix_query' => 'query: ',
        'prefix_passage' => 'passage: ',
    ],

    /* Base vectorial: Qdrant dentro de la red de Docker, con llave. «fake» con las mismas reglas que arriba. */
    'index' => [
        'driver' => env('KNOWLEDGE_INDEX', 'qdrant'),
        'url' => env('QDRANT_URL', 'http://qdrant:6333'),
        'api_key' => env('QDRANT_API_KEY', ''),
        'timeout' => 15,
    ],

    'context' => [
        /* Caracteres del contexto de la empresa. */
        'max' => 4000,
    ],

    'search' => [
        'limit' => 5,
        'query_max' => 300,
    ],

    'chunks' => [
        /* Tamaño máximo de un fragmento, en caracteres, y cuánto del anterior se repite al inicio del siguiente. */
        'size' => 800,
        'overlap' => 100,
        'max_per_tenant' => 20000,
    ],
];
