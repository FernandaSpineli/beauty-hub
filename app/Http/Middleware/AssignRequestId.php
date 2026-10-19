<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID, reusing a well-formed X-Request-Id from a proxy.
 * Stored in Laravel's Context, it is added to every log line and travels
 * with queued jobs dispatched during the request.
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(16));

        Context::add('request_id', $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
