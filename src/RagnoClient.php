<?php

declare(strict_types=1);

namespace Publicala\Ragno;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Publicala\Ragno\Exceptions\RagnoQueryException;
use stdClass;
use Throwable;

/**
 * Thin client for one Ragno service (e.g. `primary`).
 *
 * Protocol (see https://data.publica.la/api/v1/learn):
 *   - POST {base}/api/v1/db/{service}/query  body {"query": "<one read stmt>"}
 *   - Bearer auth; read-only (SELECT / WITH / SHOW / DESCRIBE / EXPLAIN).
 *   - No bindings: the statement is sent as raw SQL.
 *   - Success: {"request_id","service","data":[{col: "val", ...}, ...]}.
 *     EVERY numeric value comes back as a JSON string; NULL stays null. Rows
 *     are returned here as stdClass to mirror PDO's FETCH_OBJ shape Eloquent
 *     and the query builder expect.
 *   - Error: {"error":{"code","message","request_id",...}} with a 4xx/5xx.
 *
 * Uses Laravel's HTTP factory, so consumers can {@see \Illuminate\Support\Facades\Http::fake()}
 * the gateway in tests (or use {@see Facades\Ragno::fake()}).
 */
final readonly class RagnoClient
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private string $service,
        private string $token,
        private int $timeout = 30,
        private int $connectTimeout = 10,
        private string $userAgent = 'laravel-ragno',
    ) {}

    /**
     * Run one read-only statement and return the result rows as objects.
     *
     * @return array<int, stdClass>
     *
     * @throws RagnoQueryException
     */
    public function query(string $sql): array
    {
        if ($this->token === '') {
            throw new RagnoQueryException(
                "No Ragno token configured for service [{$this->service}]. ".
                "Set the connection's `ragno_token` (e.g. RAGNO_".mb_strtoupper($this->service).'_TOKEN).',
                errorCode: 'missing_token',
            );
        }

        $url = mb_rtrim($this->baseUrl, '/').'/api/v1/db/'.$this->service.'/query';

        try {
            $response = $this->request()->post($url, ['query' => $sql]);
        } catch (Throwable $throwable) {
            throw new RagnoQueryException(
                "Ragno request to service [{$this->service}] failed: ".$throwable->getMessage(),
                errorCode: 'transport_error',
                previous: $throwable,
            );
        }

        /** @var array<string, mixed> $body */
        $body = is_array($response->json()) ? $response->json() : [];

        if ($response->failed() || isset($body['error'])) {
            /** @var array<string, mixed> $error */
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];

            throw new RagnoQueryException(
                is_string($error['message'] ?? null) ? $error['message'] : 'Ragno query failed',
                errorCode: is_string($error['code'] ?? null) ? $error['code'] : 'query_error',
                requestId: $this->resolveRequestId($error, $response->header('X-Request-Id')),
                httpStatus: $response->status(),
            );
        }

        $rows = $body['data'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $objects = [];
        foreach ($rows as $row) {
            $objects[] = is_array($row)
                ? (object) $row
                : ($row instanceof stdClass ? $row : new stdClass);
        }

        return $objects;
    }

    /**
     * Prefer the error envelope's request_id; fall back to the response header
     * so a non-JSON / bodyless failure is still traceable.
     *
     * @param  array<string, mixed>  $error
     */
    private function resolveRequestId(array $error, string $headerRequestId): ?string
    {
        if (is_string($error['request_id'] ?? null)) {
            return $error['request_id'];
        }

        return $headerRequestId !== '' ? $headerRequestId : null;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->withToken($this->token)
            ->withUserAgent($this->userAgent)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }
}
