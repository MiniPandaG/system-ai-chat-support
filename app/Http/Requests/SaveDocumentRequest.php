<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\KbDocument;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editar un documento en revisión: título, categoría y el texto extraído. Quién puede lo decide la política.
 */
class SaveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof KbDocument && ($this->user()?->can('update', $document) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documents = config()->array('knowledge.documents');

        return [
            'title' => ['required', 'string', 'max:'.self::limit($documents, 'title_max'), 'not_regex:/[\x00-\x1F\x7F]/', 'regex:/\S/'],
            'body' => ['nullable', 'string', 'max:'.self::limit($documents, 'text_max'), $this->utf8()],
            'category' => ['nullable', 'string', 'max:'.self::limit($documents, 'category_max'), 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('knowledge.errors.title_required'),
            'title.regex' => __('knowledge.errors.title_required'),
            'title.max' => __('knowledge.errors.title_max', ['max' => config()->integer('knowledge.documents.title_max')]),
            'title.not_regex' => __('knowledge.errors.title_invalid'),
            'body.max' => __('knowledge.errors.body_max', ['max' => number_format(config()->integer('knowledge.documents.text_max'), 0, ',', '.')]),
            'category.max' => __('knowledge.errors.category_max', ['max' => config()->integer('knowledge.documents.category_max')]),
            'category.not_regex' => __('knowledge.errors.category_invalid'),
        ];
    }

    public function documentTitle(): string
    {
        return $this->string('title')->toString();
    }

    public function documentBody(): string
    {
        return $this->string('body')->toString();
    }

    public function documentCategory(): ?string
    {
        return $this->filled('category') ? $this->string('category')->toString() : null;
    }

    /**
     * @param  array<array-key, mixed>  $limits
     */
    private static function limit(array $limits, string $key): int
    {
        $value = $limits[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * El texto debe ser UTF-8 válido; si no, la limpieza lo vaciaría y el documento perdería su contenido sin avisar.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function utf8(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail(__('knowledge.errors.body_encoding'));
            }
        };
    }
}
