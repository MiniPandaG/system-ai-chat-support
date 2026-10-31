<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TenantBranding;
use Illuminate\Foundation\Http\FormRequest;

class UploadLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', TenantBranding::class) ?? false;
    }

    /**
     * Aquí solo se exige que llegue un archivo; el tipo, el tamaño y el contenido los comprueba FileStore.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['logo' => ['required', 'file']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => __('branding.logo_required'),
            'logo.file' => __('branding.logo_required'),
            'logo.uploaded' => __('files.rejected.upload_failed'),
        ];
    }
}
