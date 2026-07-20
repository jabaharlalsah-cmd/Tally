<?php

use App\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Phase 16A — the customer-integration REST API. This entry applies Laravel's `api`
        // middleware group (here just SubstituteBindings — no session, no CSRF) and an
        // automatic `/api` prefix, so routes/api.php declares 'v1/ping' to serve /api/v1/ping.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 7B — send unauthenticated guests to the right login: the platform
        // sign-in on a central domain, the tenant sign-in on a tenant subdomain.
        $middleware->redirectGuestsTo(function (Request $request) {
            return in_array($request->getHost(), (array) config('tenancy.central_domains'), true)
                ? route('platform.login')
                : route('tenant.login');
        });

        // Phase 12A — the active company MUST be resolved BEFORE route-model
        // binding runs: SubstituteBindings resolves {voucher}/{ledger}/… through
        // the BelongsToCompany global scope, and with no active company set the
        // scope is inert — a cross-company id in a URL would resolve (HTTP 200,
        // identity fields disclosed) instead of 404ing. Placing SetActiveCompany
        // ahead of SubstituteBindings in the priority order makes every binding
        // company-scoped. (Tenancy init is force-prioritized even earlier by
        // TenancyServiceProvider, and StartSession precedes both by default, so
        // the session is available here.)
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SetActiveCompany::class,
        );

        // Phase 16A — the same reasoning, for the API's identifier. IdentifyTenantByApiKey is
        // what pins the tenant AND the active company on a bearer-token request, so it must run
        // before SubstituteBindings for exactly the reason spelled out above: with no company
        // active the BelongsToCompany scope is inert, and a cross-company id in a URL would
        // resolve (HTTP 200, identity fields disclosed) instead of 404ing. 16A's only route
        // takes no bound model, so this changes nothing today — it is here so the first 16B
        // route with a {voucher} binding cannot inherit that disclosure bug by omission.
        //
        // stancl's makeTenancyMiddlewareHighestPriority() (TenancyServiceProvider) force-
        // prioritises only its OWN InitializeTenancyBy* classes; a custom identifier gets none
        // of that treatment and must ask for it here.
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IdentifyTenantByApiKey::class,
        );

        // …and once IdentifyTenantByApiKey is IN the priority list, LogApiRequest must join it.
        //
        // The sorter only orders middleware the list names; anything unlisted keeps its declared
        // position and loses every race against a listed one. So the line above — on its own —
        // hoisted IdentifyTenantByApiKey ahead of LogApiRequest even though routes/api.php
        // declares the logger first. The chain silently became:
        //
        //     IdentifyTenantByApiKey → SubstituteBindings → LogApiRequest → …
        //
        // which means a rejected request (401 invalid_key, the single most important thing to be
        // able to trace) returned before the logger ever ran: no X-Request-Id on the response,
        // and no request id for the error envelope to quote. A 200 still got one, so the gap was
        // invisible from the happy path. Naming the logger here restores it as the true outermost
        // layer. Verified by dispatching a keyless request and asserting the header is present.
        $middleware->prependToPriorityList(
            \App\Http\Middleware\IdentifyTenantByApiKey::class,
            \App\Http\Middleware\LogApiRequest::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Phase 16A — force every /api/v1/* failure into the one error envelope:
        //   {"error": {"code": …, "message": …, "details": …}}
        //
        // Scoped to 'api/v1/*' and not 'api/*' on purpose: /api/sync/* and
        // /api/subdomain-available are older surfaces with their own {"message": …} contract,
        // and re-shaping their errors would break the desktop client.
        //
        // The 500 arm is the point of this block. Laravel's default renderer will happily put an
        // exception message — and with APP_DEBUG on, the class, file, line and full stack — into
        // the response body. On a public API that is an information-disclosure bug that only
        // shows up in production, so the mapping below is deliberately independent of
        // APP_DEBUG: the client always gets a generic body plus an error_id, and the detail goes
        // to the app log under that id where support can find it.
        // Carry an HttpException's own headers onto the envelope (Retry-After on 429/503,
        // Allow on 405) — the status alone is not the whole contract.
        $withHeaders = function (\Illuminate\Http\JsonResponse $response, array $headers) {
            foreach ($headers as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        };

        $exceptions->render(function (Throwable $e, Request $request) use ($withHeaders) {
            if (! $request->is('api/v1/*')) {
                return null;   // not our surface — let the default renderer handle it
            }

            // Validation is the one known failure that is NOT an HttpException, and the only one
            // whose details are safe to hand back verbatim — they describe what the caller sent.
            if ($e instanceof ValidationException) {
                return ApiError::response(
                    'validation_failed',
                    422,
                    details: $e->errors(),
                    request: $request,
                );
            }

            // Phase 16B — a business refusal from the voucher layer with its own stable code
            // (unsupported_voucher_type, total_mismatch, …). Carries a code + status + details, so
            // it must never fall through to the generic-500 arm.
            if ($e instanceof \App\Services\Api\ApiVoucherException) {
                return ApiError::response(
                    $e->errorCode,
                    $e->status,
                    details: $e->details ?: null,
                    request: $request,
                );
            }

            // Every HTTP-shaped abort — 404s and 405s included, since NotFoundHttpException and
            // MethodNotAllowedHttpException are HttpExceptions too. Routing them all through one
            // branch is not just less code: it is what carries their headers (a 405's Allow, a
            // 503's Retry-After), which separate early branches silently dropped.
            //
            // The status is intentional, but the message may have
            // been written for a human on a web page — so map it to a code and use the envelope's
            // vetted wording rather than passing the raw string through.
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                // MAINTENANCE MODE IS NOT A CRASH. deploy.sh runs `artisan down --retry=15` on
                // every deploy, and PreventRequestsDuringMaintenance throws HttpException(503)
                // carrying a Retry-After header. An earlier revision swept that into the >=500 arm
                // below, which rewrote it to a bare 500, dropped Retry-After, and handed the client
                // an error_id nothing had logged. Every deploy window would have told every
                // integration "ZeroBook has a bug" instead of "back in 15 seconds" — so they would
                // alert their own on-call and retry immediately instead of backing off. Keep the
                // real status and the real headers.
                if ($status === 503) {
                    return $withHeaders(
                        ApiError::response('service_unavailable', 503, request: $request),
                        $e->getHeaders(),
                    );
                }

                // A genuine 5xx abort. The client gets a correlation id, so the id must actually
                // BE in the log — an unlogged error_id is a reference to nothing, and worse than
                // no id at all because support will go looking for it.
                if ($status >= 500) {
                    $errorId = (string) Str::ulid();

                    Log::error('api.http_error', [
                        'error_id' => $errorId,
                        'request_id' => $request->attributes->get(ApiError::REQUEST_ID_ATTR),
                        'path' => $request->getPathInfo(),
                        'status' => $status,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    return ApiError::internal($errorId, $request);
                }

                $code = match ($status) {
                    400 => 'bad_request',
                    401 => 'invalid_key',
                    403 => 'tenant_not_active',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    409 => 'conflict',
                    429 => 'rate_limited',
                    default => 'bad_request',
                };

                // Carry the exception's own headers through — Retry-After on a 429, Allow on a 405.
                return $withHeaders(
                    ApiError::response($code, $status, request: $request),
                    $e->getHeaders(),
                );
            }

            // Unhandled: a bug, a dead DB, a missing tenant table. The client learns nothing
            // beyond a correlation id; the real cause is logged against that id.
            $errorId = (string) Str::ulid();

            Log::error('api.internal_error', [
                'error_id' => $errorId,
                'request_id' => $request->attributes->get(ApiError::REQUEST_ID_ATTR),
                'path' => $request->getPathInfo(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return ApiError::internal($errorId, $request);
        });
    })->create();
