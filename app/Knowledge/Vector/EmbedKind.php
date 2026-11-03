<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

/** Los modelos de búsqueda por significado distinguen lo que se guarda (pasaje) de lo que se pregunta (consulta). */
enum EmbedKind: string
{
    case Passage = 'passage';
    case Query = 'query';
}
