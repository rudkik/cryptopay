<?php

use App\Exceptions\ApiException;
use App\Exceptions\ErrorResponse;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateInternal;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);

        // API-only app: there is no `login` route. Laravel's default
        // `redirectGuestsTo(fn () => route('login'))` evaluates that route inside
        // Authenticate::redirectTo(), so a guest request that does not explicitly
        // ask for JSON blew up with RouteNotFoundException -> 500 instead of the
        // SPEC §6 `unauthenticated` 401 envelope (which also defeated the SPA's
        // 401 -> /login interceptor). Returning null keeps the exception a plain
        // AuthenticationException for the renderer below.
        $middleware->redirectGuestsTo(fn () => null);

        // Laravel re-sorts route middleware by its priority list, which puts
        // ThrottleRequests ahead of anything unlisted. Without this, the API key
        // would not yet be resolved when the `api-v1` limiter builds its key, and
        // the SPEC's per-key limit would silently degrade to a per-IP one.
        // Verified with $router->gatherRouteMiddleware($route) — never assume.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateApiKey::class,
        );

        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'internal' => AuthenticateInternal::class,
            'admin' => EnsureAdminRole::class,
            'idempotency' => IdempotencyKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Single error envelope for the whole API surface (SPEC §6).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            // Rate limiters (and anything else) that carry their own response
            // must not be swallowed by the generic handler below.
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof ApiException) {
                return $e->render();
            }

            if ($e instanceof ValidationException) {
                return ErrorResponse::make(
                    'validation_error',
                    'The given data was invalid.',
                    422,
                    $e->errors(),
                );
            }

            if ($e instanceof AuthenticationException) {
                return ErrorResponse::make('unauthenticated', 'Unauthenticated.', 401);
            }

            if ($e instanceof AuthorizationException) {
                return ErrorResponse::make('forbidden', $e->getMessage() ?: 'This action is forbidden.', 403);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return ErrorResponse::make('not_found', 'Resource not found.', 404);
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                return ErrorResponse::make('not_found', 'The requested method is not supported for this route.', 405);
            }

            if ($e instanceof TooManyRequestsHttpException) {
                return ErrorResponse::make('rate_limited', 'Too many requests.', 429);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                $code = match ($status) {
                    401 => 'unauthenticated',
                    403 => 'forbidden',
                    404 => 'not_found',
                    409 => 'invalid_state',
                    422 => 'validation_error',
                    429 => 'rate_limited',
                    default => $status >= 500 ? 'server_error' : 'request_error',
                };

                return ErrorResponse::make($code, $e->getMessage() ?: 'Request failed.', $status);
            }

            return ErrorResponse::make(
                'server_error',
                config('app.debug') ? $e->getMessage() : 'Server error.',
                500,
                config('app.debug') ? ['exception' => $e::class] : [],
            );
        });
    })->create();
