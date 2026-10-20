<?php

namespace PryTax\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PryTax\Models\IdempotencyKey;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class HandleIdempotencyKey.
 *
 * Makes a POST with an `Idempotency-Key` header happen once (design.md §10): a replay of the same request by the same
 * user, within 24 hours, answers the stored response, with `Idempotent-Replayed: true`, rather than creating a second
 * receipt on a double click. The same key with another request is refused. Only successful responses are stored, so
 * a request, which failed validation, can be sent again once corrected.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Middleware
 */
class HandleIdempotencyKey
{
    /**
     * Alias of the middleware, which AppServiceProvider registers.
     *
     * @var string
     */
    public const string ALIAS = 'idempotent';

    /**
     * Header, which carries the key.
     *
     * @var string
     */
    public const string HEADER = 'Idempotency-Key';

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): Response $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(static::HEADER);
        $user = $request->user();

        if ($key === '' || !$user || !$request->isMethod('post')) {
            return $next($request);
        }

        if (strlen($key) > 64) {
            throw new UnprocessableEntityHttpException(static::HEADER . ' must be at most 64 characters.');
        }

        $hash = hash('sha256', $request->method() . ' ' . $request->path() . ' ' . $request->getContent());
        $lock = Cache::lock("idempotency:{$user->getKey()}:$key", 30);

        if (!$lock->get()) {
            throw new ConflictHttpException('A request with this ' . static::HEADER . ' is in progress.');
        }

        try {
            $stored = IdempotencyKey::query()
                ->where('user_id', $user->getKey())
                ->where('key', $key)
                ->where('created_at', '>=', now()->subDay())
                ->first();

            if ($stored) {
                if ($stored->request_hash !== $hash) {
                    throw new UnprocessableEntityHttpException(
                        'The ' . static::HEADER . ' was used for another request.'
                    );
                }

                return response($stored->response_body, $stored->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                IdempotencyKey::query()->updateOrCreate(
                    ['user_id' => $user->getKey(), 'key' => $key],
                    [
                        'request_hash' => $hash,
                        'response_status' => $response->getStatusCode(),
                        'response_body' => (string) $response->getContent(),
                        'created_at' => now(),
                    ],
                );
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
