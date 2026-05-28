<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Exceptions\RagnoConfigurationException;
use Publicala\Ragno\Facades\Ragno;
use Publicala\Ragno\RagnoConnection;
use Publicala\Ragno\RagnoManager;

it('merges the package config defaults', function (): void {
    expect(config('ragno.base_url'))->toBe('https://data.publica.la')
        ->and(config('ragno.enforce_read_only'))->toBeTrue();
});

it('registers the ragno database driver', function (): void {
    expect(DB::connection('primary'))->toBeInstanceOf(RagnoConnection::class);
});

it('uses the connection name as the service when ragno_service is omitted', function (): void {
    config()->set('database.connections.analytics', [
        'driver' => 'ragno',
        'ragno_token' => 'test-token',
    ]);

    Http::fake(['data.publica.la/api/v1/db/analytics/query' => Http::response(ragnoEnvelope([], 'analytics'))]);

    DB::connection('analytics')->select('select 1');

    Ragno::assertQueried('analytics');
});

it('lets a per-connection base url override the shared default', function (): void {
    config()->set('database.connections.staging', [
        'driver' => 'ragno',
        'ragno_service' => 'primary_staging',
        'ragno_token' => 'test-token',
        'ragno_base_url' => 'https://staging.example.test',
    ]);

    Http::fake(['staging.example.test/*' => Http::response(ragnoEnvelope([], 'primary_staging'))]);

    DB::connection('staging')->select('select 1');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://staging.example.test/api/v1/db/primary_staging/query');
});

it('registers the Ragno facade', function (): void {
    expect(Ragno::getFacadeRoot())->toBeInstanceOf(RagnoManager::class);
});

it('rejects a service name that is not a URL slug', function (string $service): void {
    config()->set('database.connections.bad', [
        'driver' => 'ragno',
        'ragno_service' => $service,
        'ragno_token' => 'test-token',
    ]);

    DB::connection('bad');
})->throws(RagnoConfigurationException::class, '[bad] is misconfigured')->with([
    'path traversal' => '../escape',
    'slash' => 'primary/extra',
    'dot' => 'primary.staging',
    'space' => 'primary staging',
    'empty' => '',
    'url-encoded slash' => 'primary%2Fextra',
]);

it('rejects a base URL that is not absolute http(s)', function (string $baseUrl): void {
    config()->set('database.connections.bad', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => 'test-token',
        'ragno_base_url' => $baseUrl,
    ]);

    DB::connection('bad');
})->throws(RagnoConfigurationException::class, '[bad] is misconfigured')->with([
    'file scheme' => 'file:///etc/passwd',
    'ftp scheme' => 'ftp://example.test',
    'relative path' => '/api/v1',
    'not a URL' => 'not-a-url',
    'empty' => '',
]);

it('accepts a service name with letters, numbers, hyphens, and underscores', function (): void {
    config()->set('database.connections.ok', [
        'driver' => 'ragno',
        'ragno_service' => 'primary-staging_v2',
        'ragno_token' => 'test-token',
    ]);

    expect(DB::connection('ok'))->toBeInstanceOf(RagnoConnection::class);
});
