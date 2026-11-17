<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anyone can POST to the webhook URL, so a request is only let through when it carries
 * a signature made with our signing secret: "t=<unix time>,v1=<hex HMAC-SHA256 of
 * '<t>.<raw body>'>".
 */
class VerifyPixelforgeSignature
{
    // A signature older than this is refused, so a captured request can't be replayed later.
    private const TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('pixelforge.webhook_secret');

        $signature = [];
        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);

        // Signed over the raw body, exactly as it was sent.
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", (string) $secret);

        $valid = filled($secret)
            && hash_equals($expected, $signature['v1'] ?? '')
            && abs(time() - $timestamp) <= self::TOLERANCE_SECONDS;

        abort_unless($valid, 400, 'Invalid Pixelforge signature.');

        return $next($request);
    }
}
