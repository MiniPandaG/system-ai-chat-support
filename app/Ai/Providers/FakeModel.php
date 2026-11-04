<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Completion;
use App\Ai\CompletionRequest;
use Closure;

/**
 * Un proveedor de mentira para pruebas e integración continua: responde lo que se le programe y guarda lo que recibe. No
 * llama a nadie. La aplicación se niega a arrancar con él en producción.
 */
final class FakeModel implements LanguageModel
{
    /** @var list<CompletionRequest> */
    public array $requests = [];

    /** @var (Closure(CompletionRequest): Completion)|null */
    public ?Closure $handler = null;

    public function complete(CompletionRequest $request): Completion
    {
        $this->requests[] = $request;

        if ($this->handler !== null) {
            return ($this->handler)($request);
        }

        $input = 0;

        foreach ($request->messages as $message) {
            $input += max(1, intdiv(strlen($message->content), 4));
        }

        return new Completion('Respuesta de prueba.', $input, 5, 'stop');
    }
}
