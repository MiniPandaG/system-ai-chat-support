<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Metrics\Range;
use App\Models\CsatResponse;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Lo que la pantalla de métricas puede pedir por la dirección: un rango (hoy, 7 días, 30 días o fechas propias de hasta 366
 * días, sin pasar de hoy) y, para los comentarios, un puntaje de 1 a 5. Es una pantalla que se abre con un enlace, así que
 * un valor inválido no la rompe ni redirige: se ignora y la pantalla avisa y muestra el rango de 30 días.
 */
class MetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CsatResponse::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function range(): Range
    {
        return Range::make($this->preset(), $this->text('from'), $this->text('to'));
    }

    public function preset(): string
    {
        $preset = $this->text('range');

        return $preset === null || $preset === '' ? '30d' : $preset;
    }

    public function score(): ?int
    {
        $score = $this->text('score');

        return $score !== null && preg_match('/\A[1-5]\z/', $score) === 1 ? (int) $score : null;
    }

    private function text(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) ? $value : null;
    }
}
