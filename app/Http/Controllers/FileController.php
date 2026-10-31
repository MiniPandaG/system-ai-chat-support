<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de archivos de la empresa. Se llega con una URL firmada que caduca, y aun así hace falta sesión y ser de
 * la empresa dueña: una URL filtrada no sirve a otra persona. El archivo se resuelve con el filtro automático, así
 * que el de otra empresa da 404.
 */
class FileController extends Controller
{
    public function show(StoredFile $file): StreamedResponse
    {
        Gate::authorize('view', $file);

        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->purpose->value.'.png', [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
