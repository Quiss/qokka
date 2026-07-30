<?php

namespace App\Services;

use App\Exceptions\TelegramApiServerException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramApiServer
{
    /** @return array<string, array{session: string, file: string, status: string|int}> */
    public function sessions(): array
    {
        $response = $this->system('getSessionList');
        $sessions = $response['sessions'] ?? [];

        return is_array($sessions) ? $sessions : [];
    }

    public function addSession(string $session): void
    {
        $this->system('addSession', ['session' => $session]);
    }

    public function sessionStatus(string $session): string|int|null
    {
        return $this->sessions()[$session]['status'] ?? null;
    }

    public function isLoggedIn(string $session): bool
    {
        return $this->sessionStatus($session) === 'LOGGED_IN';
    }

    /** @return array<string, mixed> */
    public function healthcheck(): array
    {
        return $this->system('healthcheck');
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function call(string $session, string $method, array $parameters = []): array
    {
        $response = $this->request()->post(
            '/api/'.rawurlencode($session).'/'.$method,
            $parameters,
        );

        return $this->response($response);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function system(string $method, array $parameters = []): array
    {
        $response = $this->request()->post('/system/'.$method, $parameters);

        return $this->response($response);
    }

    public function request(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.telegram.api_server.url'), '/'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.telegram.api_server.connect_timeout', 5))
            ->timeout($timeout ?? (int) config('services.telegram.api_server.timeout', 30));
    }

    /** @return array<string, mixed> */
    public function response(Response $response): array
    {
        $payload = $response->json();

        if (
            $response->successful()
            && is_array($payload)
            && ($payload['success'] ?? false) === true
        ) {
            $result = $payload['response'] ?? [];

            return is_array($result) ? $result : ['value' => $result];
        }

        throw $this->exception(
            is_array($payload) ? $payload : [],
            $response->status(),
        );
    }

    /** @param array<string, mixed> $payload */
    public function exception(array $payload, int $status = 0): TelegramApiServerException
    {
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
        $error = is_array($errors[0] ?? null) ? $errors[0] : [];
        $message = trim((string) ($error['message'] ?? ''));

        if ($message === '') {
            $message = $status > 0
                ? "TelegramApiServer вернул HTTP {$status}."
                : 'TelegramApiServer вернул некорректный ответ.';
        }

        return new TelegramApiServerException(
            $message,
            $this->rpcCode($message),
            is_numeric($error['code'] ?? null) ? (int) $error['code'] : $status,
            is_string($error['exception'] ?? null) ? $error['exception'] : null,
        );
    }

    public function isReachable(): bool
    {
        try {
            $this->sessions();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function rpcCode(string $message): ?string
    {
        if (preg_match('/\b([A-Z][A-Z0-9_]{2,})\b/', $message, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
