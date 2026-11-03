<?php

declare(strict_types=1);

namespace App\Knowledge;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Saca el texto de un documento ya declarado limpio por el antivirus. Un PDF es un formato complejo y hostil: se lee sin
 * imágenes, con un tope de memoria al descomprimir, de un contenido ya acotado en peso y en un trabajo en cola con tiempo
 * máximo. Lo que sale es texto y nada más: se limpia como todo lo que entra a la base (RS-14) y se comprueba su tamaño.
 */
class TextExtractor
{
    /**
     * @return array{text: string, pages: int|null}
     *
     * @throws ExtractionFailed
     */
    public function extract(string $type, string $content): array
    {
        [$text, $pages] = $type === 'pdf' ? $this->fromPdf($content) : [$content, null];

        $text = TextCleaner::clean($text);
        $text = (string) preg_replace(["/[ \t]+\n/", "/\n{3,}/"], ["\n", "\n\n"], $text);
        $text = trim($text);

        if ($text === '') {
            throw new ExtractionFailed(DocumentFailure::NoText);
        }

        if (mb_strlen($text) > config()->integer('knowledge.documents.text_max')) {
            throw new ExtractionFailed(DocumentFailure::TextTooLong);
        }

        return ['text' => $text, 'pages' => $pages];
    }

    /**
     * @return array{string, int}
     *
     * @throws ExtractionFailed
     */
    private function fromPdf(string $content): array
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(config()->integer('knowledge.documents.decode_memory_bytes'));

        try {
            $document = (new Parser([], $config))->parseContent($content);
            $pages = $document->getPages();
        } catch (Throwable $error) {
            // El lector avisa de un PDF protegido con un texto fijo; cualquier otra cosa es un archivo que no se puede leer.
            throw new ExtractionFailed(str_contains(strtolower($error->getMessage()), 'secured') ? DocumentFailure::PdfEncrypted : DocumentFailure::PdfUnreadable);
        }

        if (count($pages) > config()->integer('knowledge.documents.max_pages')) {
            throw new ExtractionFailed(DocumentFailure::TooManyPages);
        }

        $text = '';
        $limit = config()->integer('knowledge.documents.text_max') * 2;

        try {
            foreach ($pages as $page) {
                $text .= $page->getText()."\n\n";

                // Una página tras otra hasta pasarse con holgura del máximo: no hace falta leer el resto para rechazarlo.
                if (strlen($text) > $limit) {
                    break;
                }
            }
        } catch (Throwable) {
            throw new ExtractionFailed(DocumentFailure::PdfUnreadable);
        }

        return [$text, count($pages)];
    }
}
