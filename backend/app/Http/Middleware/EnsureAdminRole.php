<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\ErrorResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards admin-only endpoints. Viewers keep read access; anything mutating
 * requires role=admin.
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ErrorResponse::make('unauthenticated', 'Unauthenticated.', 401);
        }

        if (! $user->is_active) {
            return ErrorResponse::make('forbidden', 'This account is disabled.', 403);
        }

        if ($user->role !== UserRole::Admin) {
            return ErrorResponse::make('forbidden', 'This action requires the admin role.', 403);
        }

        return $next($request);
    }
}
