<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\KbDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Subir un documento. Aquí solo se comprueba que llegó un archivo; el tipo, el peso y el contenido los juzga
 * `DocumentStore` mirando lo que el archivo realmente contiene.
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', KbDocument::class) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('knowledge.errors.file_required'),
            'file.file' => __('files.rejected.upload_failed'),
        ];
    }

    public function document(): UploadedFile
    {
        $file = $this->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }
}
