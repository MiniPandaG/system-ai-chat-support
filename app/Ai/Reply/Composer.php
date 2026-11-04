<?php

declare(strict_types=1);

namespace App\Ai\Reply;

use App\Knowledge\TextCleaner;
use App\Models\AiRun;
use App\Models\AiSettings;

/**
 * Arma el texto final que verá el cliente a partir de lo que respondió la IA: las fuentes que la empresa quiere mostrar y,
 * por correo (donde no hay un nombre de autor a la vista), el aviso de que lo escribió el asistente virtual. Lo añade el
 * código, no el modelo, así que no depende de que el modelo lo recuerde ni se lo puede saltar.
 */
class Composer
{
    public function compose(AiRun $run, AiSettings $settings): string
    {
        $text = $run->body ?? '';
        $titles = [];

        foreach ($run->sources ?? [] as $source) {
            $title = mb_substr(TextCleaner::line($source['title']), 0, 80);

            if ($title !== '' && ! in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        if ($settings->show_sources && $titles !== []) {
            $text .= "\n\n".__('ai.sources_line', ['titles' => implode(', ', $titles)]);
        }

        if ($run->channel === 'email' && $settings->identify_as_ai) {
            $text .= "\n\n".__('ai.email_signature');
        }

        return $text;
    }
}
