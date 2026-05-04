<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Closure;
use Illuminate\Http\Request;

class SessionAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('X-Auth-Token');

        if (!$token) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user = AuthToken::findValid($token);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);

        return $next($request);
    }
}
