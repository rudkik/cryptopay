<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            return ErrorResponse::make('forbidden', 'This account is disabled.', 403);
        }

        return $next($request);
    }
}
