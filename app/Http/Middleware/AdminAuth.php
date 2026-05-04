<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Admin-Token') ?? $request->query('admin_token');

        if (!$token) {
            return response()->json(['error' => 'Admin token required'], 401);
        }

        try {
            $payload = json_decode(base64_decode($token), true);

            if (!$payload || !isset($payload['u'], $payload['exp'], $payload['sig'], $payload['date'])) {
                throw new \Exception('Malformed token');
            }

            // Check expiry
            if ($payload['exp'] < now()->timestamp) {
                return response()->json(['error' => 'Admin session expired'], 401);
            }

            // Verify signature using the date embedded in the token (not today's date)
            $expectedSig = hash_hmac(
                'sha256',
                $payload['u'] . $payload['date'],
                config('app.key')
            );

            if (!hash_equals($expectedSig, $payload['sig'])) {
                throw new \Exception('Invalid signature');
            }

            $request->merge(['admin_user' => $payload['u']]);

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid admin token'], 401);
        }

        return $next($request);
    }
}
