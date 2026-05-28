<?php

declare(strict_types=1);

namespace Publicala\Ragno;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Exceptions\RagnoQueryException;
use Throwable;

/**
 * Test-time helpers and small utilities for working with Ragno, exposed via the
 * `Ragno` facade.
 *
 * The faking story is intentionally a thin layer over Laravel's own HTTP client
 * fake — the driver speaks HTTP, so {@see Http::fake()} already intercepts it.
 * These helpers just spell the gateway's URL shape and envelope for you, and
 * they operate on the same facade root the driver's client uses, so a fake set
 * here is guaranteed to apply to queries.
 */
final readonly class RagnoManager
{
    public function __construct(
        private string $baseUrl = 'https://data.publica.la',
    ) {}

    /**
     * Fake the gateway. Keys are service names; values are either an array of
     * rows (wrapped in a success envelope) or any value Http::fake() accepts
     * (a Response, a closure, ...). Any un-stubbed service returns empty data.
     *
     * Example:
     *   Ragno::fake(['primary' => [['id' => '1', 'name' => 'Acme']]]);
     *
     * @param  array<string, mixed>  $services
     */
    public function fake(array $services = []): void
    {
        $stubs = [];

        foreach ($services as $service => $value) {
            $stubs[$this->urlFor($service)] = $this->toResponse($service, $value);
        }

        // Catch-all so an un-stubbed read returns a valid empty result set
        // instead of hitting the network.
        $stubs[$this->urlFor('*')] = Http::response([
            'request_id' => 'fake-request',
            'service' => 'fake',
            'data' => [],
        ]);

        Http::fake($stubs);
    }

    /**
     * Assert that a query was sent to the given service. The optional callback
     * receives the SQL string and the request, and must return true to match.
     */
    public function assertQueried(string $service, ?Closure $callback = null): void
    {
        $needle = $this->pathFor($service);

        Http::assertSent(function (Request $request) use ($needle, $callback): bool {
            if (! str_contains((string) $request->url(), $needle)) {
                return false;
            }

            if (! $callback instanceof Closure) {
                return true;
            }

            $data = $request->data();

            return (bool) $callback(is_string($data['query'] ?? null) ? $data['query'] : '', $request);
        });
    }

    /**
     * Assert that no Ragno query was sent (to any service, or to one service).
     */
    public function assertNothingQueried(?string $service = null): void
    {
        $needle = $service === null ? '/api/v1/db/' : $this->pathFor($service);

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->url(), $needle));
    }

    /**
     * The recorded [request, response] pairs for Ragno queries, optionally
     * filtered to one service.
     *
     * @return Collection<int, mixed>
     */
    public function recorded(?string $service = null): Collection
    {
        $needle = $service === null ? '/api/v1/db/' : $this->pathFor($service);

        return Http::recorded(fn (Request $request): bool => str_contains((string) $request->url(), $needle));
    }

    /**
     * Dig the Ragno `request_id` out of a thrown exception. Connection-level
     * failures are wrapped in a QueryException by Laravel, so walk the
     * `previous` chain to find the underlying {@see RagnoQueryException}.
     */
    public function requestId(Throwable $e): ?string
    {
        return $this->exceptionFrom($e)?->requestId;
    }

    /**
     * Dig the underlying {@see RagnoQueryException} out of a thrown exception
     * (e.g. a QueryException raised by the connection), if any.
     */
    public function exceptionFrom(Throwable $e): ?RagnoQueryException
    {
        for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof RagnoQueryException) {
                return $current;
            }
        }

        return null;
    }

    private function toResponse(string $service, mixed $value): mixed
    {
        if (is_array($value)) {
            return Http::response([
                'request_id' => 'fake-request',
                'service' => $service,
                'data' => array_values($value),
            ]);
        }

        // A Response, a closure, a sequence — hand straight to Http::fake().
        return $value;
    }

    private function urlFor(string $service): string
    {
        return mb_rtrim($this->baseUrl, '/').$this->pathFor($service);
    }

    private function pathFor(string $service): string
    {
        return '/api/v1/db/'.$service.'/query';
    }
}
