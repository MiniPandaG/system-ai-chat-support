<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Branding\BrandColor;
use App\Models\TenantBranding;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaletteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', TenantBranding::class) ?? false;
    }

    /**
     * Lista cerrada de claves (array:…) y formato exacto de cada color. El contraste lo juzga Palette::check.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'colors' => ['present', 'array:'.implode(',', BrandColor::keys())],
            'colors.*' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colors.array' => __('branding.palette.format'),
            'colors.*.regex' => __('branding.palette.format'),
            'colors.*.required' => __('branding.palette.format'),
            'colors.*.string' => __('branding.palette.format'),
        ];
    }
}
