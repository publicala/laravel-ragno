<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
