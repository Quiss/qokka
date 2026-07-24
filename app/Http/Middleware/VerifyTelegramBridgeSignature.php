<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramBridgeSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.telegram.bridge_secret');
        $timestamp = $request->header('X-Telegram-Timestamp');
        $nonce = $request->header('X-Telegram-Nonce');
        $signature = $request->header('X-Telegram-Signature');

        if (
            $secret === ''
            || ! ctype_digit((string) $timestamp)
            || ! is_string($nonce)
            || ! preg_match('/\A[0-9a-f-]{32,64}\z/i', $nonce)
            || blank($signature)
        ) {
            abort(401, 'Invalid Telegram bridge signature.');
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > 60) {
            abort(401, 'Expired Telegram bridge signature.');
        }

        $signedPayload = $timestamp.'.'.$nonce.'.'.$request->getContent();
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        if (! hash_equals($expected, (string) $signature)) {
            abort(401, 'Invalid Telegram bridge signature.');
        }

        if (! Cache::add('telegram-bridge:'.hash('sha256', $signedPayload.'.'.$signature), true, 120)) {
            abort(409, 'Telegram bridge request was already processed.');
        }

        return $next($request);
    }
}
