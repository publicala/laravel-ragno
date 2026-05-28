<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Exceptions\RagnoConfigurationException;
use Publicala\Ragno\Exceptions\RagnoQueryException;
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

// HTTPS-only in production
//
// An http:// base URL in prod sends the bearer token in plaintext to anyone on
// path. We require https:// there. Outside production (local dev against Herd,
// container gateways) http:// is still allowed — same default as before.

it('rejects an http:// base URL when APP_ENV=production', function (): void {
    app()->instance('env', 'production');

    config()->set('database.connections.bad', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => 'test-token',
        'ragno_base_url' => 'http://data.publica.la',
    ]);

    DB::connection('bad');
})->throws(
    RagnoConfigurationException::class,
    'The ragno_base_url must be an absolute https:// URL in production',
);

it('accepts an https:// base URL when APP_ENV=production', function (): void {
    app()->instance('env', 'production');

    config()->set('database.connections.ok', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => 'test-token',
        'ragno_base_url' => 'https://data.publica.la',
    ]);

    expect(DB::connection('ok'))->toBeInstanceOf(RagnoConnection::class);
});

it('still accepts http:// outside production (local dev)', function (): void {
    // Testbench boots with env=testing — already non-production. Be explicit
    // so the contract is legible and the regression hard to lose.
    app()->instance('env', 'local');

    config()->set('database.connections.localdev', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => 'test-token',
        'ragno_base_url' => 'http://primary.test',
    ]);

    expect(DB::connection('localdev'))->toBeInstanceOf(RagnoConnection::class);
});

// Token control-char sanitization
//
// `\r` / `\n` would let a malicious env value inject additional HTTP headers
// when Laravel builds `Authorization: Bearer <token>`. The whole `\x00-\x1F`
// plus `\x7F` (DEL) range is rejected as defense-in-depth. Sanctum/PASETO
// tokens use only printable ASCII, so legitimate tokens are unaffected.

it('rejects a token containing control characters', function (string $token): void {
    config()->set('database.connections.bad', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => $token,
    ]);

    DB::connection('bad');
})->throws(
    RagnoConfigurationException::class,
    'The ragno_token must not contain control characters',
)->with([
    'CRLF' => "1|abc\r\ndef",
    'CR alone' => "1|abc\rdef",
    'LF alone' => "1|abc\ndef",
    'TAB' => "1|abc\tdef",
    'NUL' => "1|abc\x00def",
    'DEL' => "1|abc\x7Fdef",
    'low control byte' => "1|abc\x01def",
    'trailing newline' => "1|abcdef\n",
]);

it('accepts a Sanctum-shaped token (id|alphanumeric)', function (): void {
    config()->set('database.connections.ok', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => '42|abcDEF0123456789_-.',
    ]);

    expect(DB::connection('ok'))->toBeInstanceOf(RagnoConnection::class);
});

it('lets an empty token resolve so missing_token still fires at query time', function (): void {
    config()->set('database.connections.untokened', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => '',
    ]);

    $connection = DB::connection('untokened');

    expect($connection)->toBeInstanceOf(RagnoConnection::class);

    // Laravel's Connection::run() wraps the underlying RagnoQueryException in a
    // QueryException; the friendly `missing_token` message is preserved on the
    // previous chain — which `Ragno::exceptionFrom()` is the documented way to
    // unwrap. Config-level validation intentionally accepts empty tokens so
    // this surface keeps working.
    try {
        $connection->select('select 1');
        $this->fail('expected the connection to throw a missing_token error');
    } catch (Throwable $throwable) {
        $inner = Ragno::exceptionFrom($throwable);
        expect($inner)->toBeInstanceOf(RagnoQueryException::class)
            ->and($inner?->errorCode)->toBe('missing_token')
            ->and($inner?->getMessage())->toContain('No Ragno token configured');
    }
});
