<?php

namespace App\Services\Portal;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Staff Web Portal's only way to business data and rules (Phase 34A,
 * DEC-063 W-1): it dispatches a request to the existing `/api/v1`
 * routes **in-process**, through the HTTP kernel, as the signed-in web
 * session user. The API's own middleware (`auth:sanctum` resolves the
 * `web` guard — config/sanctum.php — and `account.active`), FormRequests,
 * policies, company-day handling and resources therefore apply exactly as
 * they do for the mobile app. This class holds no business rules.
 *
 * The inner request:
 * - carries the outer request's client identity (IP, user agent,
 *   forwarded headers) so throttling and audit entries describe the real
 *   browser, and asks for JSON. The user is the one the outer request
 *   already signed in on the `web` guard, which `auth:sanctum` reads;
 * - is marked with a request attribute (never client-settable) so the
 *   audit log records `source: web` (AuditLogger, W-6);
 * - never creates a token.
 *
 * The API's middleware always runs, even when the calling request runs
 * without middleware (Livewire's test harness does that): the API's own
 * sign-in, account and permission checks are the point of this class.
 *
 * Afterwards the outer request, the router's current route and the
 * default auth guard (the API's `auth:sanctum` switches it) are put
 * back, so the calling page keeps rendering as itself.
 *
 * Not `final` only so a test can substitute a failing double.
 */
class InternalApi
{
    public const ATTRIBUTE = 'portal.internal';

    /** Outer server keys the inner request keeps (client identity only). */
    private const FORWARDED_SERVER_KEYS = [
        'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'HTTPS',
        'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_PROTO',
        'HTTP_ACCEPT_LANGUAGE',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Kernel $kernel,
    ) {}

    public static function isInternal(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true;
    }

    /**
     * `GET /api/v1{$path}` and the decoded JSON body.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws PortalApiException for any non-2xx answer
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, query: $query);
    }

    /**
     * Any method with an optional JSON body, as the mobile app sends it.
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     *
     * @throws PortalApiException for any non-2xx answer
     */
    public function send(string $method, string $path, array $query = [], array $json = []): array
    {
        $content = $method === 'GET' ? null : json_encode((object) $json, JSON_THROW_ON_ERROR);

        return $this->decode($this->dispatch($method, $path, $query, $content));
    }

    /**
     * `POST` one file (multipart, under `$field`) to an attachment
     * endpoint, as the mobile app does (Phase 34E, W-8). The file must
     * already be on this server (a Livewire temporary upload); it is
     * forwarded as a trusted local file so the API's own validation and
     * storage run unchanged.
     *
     * @return array<string, mixed>
     *
     * @throws PortalApiException for any non-2xx answer
     */
    public function upload(string $path, string $field, string $localPath, string $originalName, ?string $mimeType): array
    {
        $file = new UploadedFile($localPath, $originalName, $mimeType, null, true);

        return $this->decode($this->dispatch('POST', $path, [], null, [$field => $file]));
    }

    /**
     * `GET /api/v1{$path}` and the API's own response, unread — for a
     * download (Phase 34E): the caller streams it to the browser.
     *
     * @param  array<string, scalar|null>  $query
     *
     * @throws PortalApiException for any non-2xx answer
     */
    public function raw(string $path, array $query = []): Response
    {
        $response = $this->dispatch('GET', $path, $query, null);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->decode($response);
        }

        return $response;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, UploadedFile>  $files
     */
    private function dispatch(string $method, string $path, array $query, ?string $content, array $files = []): Response
    {
        $outer = $this->app->make('request');

        $uri = '/api/v1/'.ltrim($path, '/');
        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $server = array_intersect_key($outer->server->all(), array_flip(self::FORWARDED_SERVER_KEYS));
        $server['HTTP_ACCEPT'] = 'application/json';
        if ($content !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $inner = Request::create($uri, $method, files: $files, server: $server, content: $content);
        $inner->attributes->set(self::ATTRIBUTE, true);

        $router = $this->app->make('router');
        $route = $router->current();
        $routeRequest = $router->getCurrentRequest();
        $auth = $this->app->make('auth');
        $guard = $auth->getDefaultDriver();
        // Both the kernel and the router skip middleware only when this is
        // exactly `true`; `false` runs it for the inner call.
        $middlewareDisabled = $this->app->bound('middleware.disable') ? $this->app->make('middleware.disable') : null;
        if ($middlewareDisabled !== null) {
            $this->app->instance('middleware.disable', false);
        }

        try {
            return $this->kernel->handle($inner);
        } finally {
            if ($middlewareDisabled !== null) {
                $this->app->instance('middleware.disable', $middlewareDisabled);
            }
            $auth->shouldUse($guard);
            $this->app->instance('request', $outer);
            Facade::clearResolvedInstance('request');
            $this->restoreRouter($router, $route, $routeRequest);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PortalApiException for any non-2xx answer
     */
    private function decode(Response $response): array
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true);
        $body = is_array($body) ? $body : [];

        if ($status >= 200 && $status < 300) {
            return $body;
        }

        throw PortalApiException::fromResponse($status, $body);
    }

    private function restoreRouter(Router $router, mixed $route, mixed $request): void
    {
        Closure::bind(function () use ($route, $request): void {
            $this->current = $route;
            $this->currentRequest = $request;
        }, $router, Router::class)();
    }
}
