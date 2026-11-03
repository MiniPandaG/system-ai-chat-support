<?php

declare(strict_types=1);

namespace App\Knowledge;

/** Por qué un documento no pudo procesarse. Lista cerrada: se muestra con su mensaje, nunca con el error técnico. */
enum DocumentFailure: string
{
    case ScanUnavailable = 'scan_unavailable';
    case FileMissing = 'file_missing';
    case PdfUnreadable = 'pdf_unreadable';
    case PdfEncrypted = 'pdf_encrypted';
    case TooManyPages = 'too_many_pages';
    case TextTooLong = 'text_too_long';
    case NoText = 'no_text';
    case ExtractionFailed = 'extraction_failed';

    public function message(): string
    {
        return (string) __('knowledge.documents.failures.'.$this->value, [
            'pages' => config()->integer('knowledge.documents.max_pages'),
            'max' => number_format(config()->integer('knowledge.documents.text_max'), 0, ',', '.'),
        ]);
    }
}
