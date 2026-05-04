<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BanCheck
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->is_banned) {
            return response()->json(['error' => 'Account suspended'], 403);
        }
        return $next($request);
    }
}
