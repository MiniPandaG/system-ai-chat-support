<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WidgetOrigin;
use App\Widget\WidgetCorner;
use App\Widget\WidgetMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El modo y la esquina del widget: dos listas cerradas. Nada más se acepta.
 */
class UpdateWidgetAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', WidgetOrigin::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(WidgetMode::class)],
            'corner' => ['required', Rule::enum(WidgetCorner::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => __('widget.page.errors.mode'),
            'mode.enum' => __('widget.page.errors.mode'),
            'corner.required' => __('widget.page.errors.corner'),
            'corner.enum' => __('widget.page.errors.corner'),
        ];
    }

    public function widgetMode(): WidgetMode
    {
        return WidgetMode::from($this->string('mode')->toString());
    }

    public function widgetCorner(): WidgetCorner
    {
        return WidgetCorner::from($this->string('corner')->toString());
    }
}
