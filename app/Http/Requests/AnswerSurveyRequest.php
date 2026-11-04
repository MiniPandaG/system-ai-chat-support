<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La respuesta a una encuesta de satisfacción: un puntaje entero de 1 a 5 y un comentario opcional de texto plano. El
 * enlace es el único permiso que se necesita, así que no hay nada que autorizar aquí.
 */
class AnswerSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.config()->integer('csat.comment_max')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $max = config()->integer('csat.comment_max');

        return [
            'score.required' => __('survey.errors.score'),
            'score.integer' => __('survey.errors.score'),
            'score.between' => __('survey.errors.score'),
            'comment.max' => __('survey.errors.comment', ['max' => $max]),
            'comment.string' => __('survey.errors.comment', ['max' => $max]),
        ];
    }

    public function score(): int
    {
        return $this->integer('score');
    }

    public function comment(): ?string
    {
        $comment = $this->input('comment');

        return is_string($comment) ? $comment : null;
    }
}
