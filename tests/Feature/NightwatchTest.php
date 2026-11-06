<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Publicala\Ragno\RagnoServiceProvider;

beforeEach(function (): void {
    // Record outgoing requests the way an app with Nightwatch enabled does.
    Http::globalMiddleware(resolve(Core::class)->guzzleMiddleware());
});

/**
 * The URL and status code of every outgoing request Nightwatch kept.
 *
 * @return list<array<mixed>>
 */
function nightwatchOutgoingRequests(): array
{
    $records = collect(resolve(Core::class)->ingest->buffer->all());

    return $records
        ->where('t', 'outgoing-request')
        ->map(fn (array $record): array => Arr::only($record, ['url', 'status_code']))
        ->values()
        ->all();
}

it('keeps a successful read out of Nightwatch', function (): void {
    Http::fake(['data.publica.la/api/v1/db/primary/query' => Http::response(ragnoEnvelope([['id' => '1']]))]);

    DB::connection('primary')->select('select 1');

    Http::assertSentCount(1);
    expect(nightwatchOutgoingRequests())->toBe([]);
});

it('keeps a failed read in Nightwatch with its status code', function (int $status): void {
    Http::fake(['data.publica.la/api/v1/db/primary/query' => Http::response(['error' => 'nope'], $status)]);

    expect(fn () => DB::connection('primary')->select('select 1'))->toThrow(QueryException::class)
        ->and(nightwatchOutgoingRequests())->toBe([['url' => 'https://data.publica.la/api/v1/db/primary/query', 'status_code' => $status]]);
})->with([101, 401, 429, 500, 503]);

it('matches a connection with its own base URL and service', function (string $baseUrl): void {
    config()->set('database.connections.analytics', [
        'driver' => 'ragno',
        'ragno_service' => 'reporting',
        'ragno_token' => 'test-token',
        'ragno_base_url' => $baseUrl,
    ]);

    Http::fake(['gateway.acme.test/ragno/api/v1/db/reporting/query' => Http::response(ragnoEnvelope([], 'reporting'))]);

    DB::connection('analytics')->select('select 1');

    Http::assertSentCount(1);
    expect(nightwatchOutgoingRequests())->toBe([]);
})->with([
    'with a base path, an explicit port and an uppercase host' => 'https://Gateway.Acme.test:443/ragno/',
    'with credentials in the URL' => 'https://acme:secret@gateway.acme.test/ragno',
]);

it('keeps requests the driver does not make', function (string $method, string $url): void {
    DB::connection('primary');

    Http::fake(['*' => Http::response()]);

    Http::send($method, $url);

    expect(nightwatchOutgoingRequests())->toBe([['url' => $url, 'status_code' => 200]]);
})->with([
    'another host' => ['POST', 'https://api.acme.test/orders'],
    'another gateway path' => ['POST', 'https://data.publica.la/api/v1/services'],
    'a service no connection resolved' => ['POST', 'https://data.publica.la/api/v1/db/reporting/query'],
    'another method on a resolved endpoint' => ['GET', 'https://data.publica.la/api/v1/db/primary/query'],
]);

it('boots when the app does not register Nightwatch', function (): void {
    app()->forgetInstance(Core::class);
    Nightwatch::clearResolvedInstances();

    new RagnoServiceProvider(app())->boot();

    expect(app()->bound(Core::class))->toBeFalse();
});
