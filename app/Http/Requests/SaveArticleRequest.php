<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\KbArticle;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear o editar un artículo: título de una línea, contenido en Markdown (texto plano) y categoría corta. Quién puede
 * hacerlo lo decide la política; el artículo de la ruta ya viene filtrado por empresa.
 */
class SaveArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $article = $this->route('article');

        if ($article instanceof KbArticle) {
            return $this->user()?->can('update', $article) ?? false;
        }

        return $this->user()?->can('create', KbArticle::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $articles = config()->array('knowledge.articles');

        return [
            'title' => ['required', 'string', 'max:'.self::limit($articles, 'title_max'), 'not_regex:/[\x00-\x1F\x7F]/', 'regex:/\S/'],
            'body' => ['nullable', 'string', 'max:'.self::limit($articles, 'body_max'), $this->utf8()],
            'category' => ['nullable', 'string', 'max:'.self::limit($articles, 'category_max'), 'not_regex:/[\x00-\x1F\x7F]/'],
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
            'title.max' => __('knowledge.errors.title_max', ['max' => config()->integer('knowledge.articles.title_max')]),
            'title.not_regex' => __('knowledge.errors.title_invalid'),
            'body.max' => __('knowledge.errors.body_max', ['max' => config()->integer('knowledge.articles.body_max')]),
            'category.max' => __('knowledge.errors.category_max', ['max' => config()->integer('knowledge.articles.category_max')]),
            'category.not_regex' => __('knowledge.errors.category_invalid'),
        ];
    }

    public function articleTitle(): string
    {
        return $this->string('title')->toString();
    }

    public function articleBody(): string
    {
        return $this->string('body')->toString();
    }

    public function articleCategory(): ?string
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
     * El texto debe ser UTF-8 válido; si no, la limpieza lo vaciaría y el artículo perdería su contenido sin avisar.
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
