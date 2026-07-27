<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Publicala\Ragno\Facades\Ragno;

// The gateway's audit log only knows what the driver tells it, so every request
// carries the app's own name next to the package's: `laravel-ragno (Acme
// Books)`. `ragno_user_agent` (per connection) and `RAGNO_USER_AGENT` (shared)
// still replace the header outright.

it('sends the driver name and the app name', function (): void {
    config()->set('app.name', 'Acme Books');

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    expect(lastRagnoUserAgent())->toBe('laravel-ragno (Acme Books)');
});

it('falls back to the bare driver name when the app has no name', function (): void {
    config()->set('app.name', '');

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    expect(lastRagnoUserAgent())->toBe('laravel-ragno');
});

it('lets the shared user agent replace the header', function (): void {
    config()->set('app.name', 'Acme Books');
    config()->set('ragno.user_agent', 'acme-reporting/2.1');

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    expect(lastRagnoUserAgent())->toBe('acme-reporting/2.1');
});

it('lets a per-connection user agent win over the shared one', function (): void {
    config()->set('ragno.user_agent', 'acme-reporting/2.1');
    config()->set('database.connections.analytics', [
        'driver' => 'ragno',
        'ragno_service' => 'analytics',
        'ragno_token' => 'test-token',
        'ragno_user_agent' => 'acme-nightly-export',
    ]);

    Ragno::fake();

    DB::connection('analytics')->select('select 1');

    expect(lastRagnoUserAgent())->toBe('acme-nightly-export');
});

// `APP_NAME` is free text. A control byte in a header value makes the HTTP
// client reject the request, and a paren (or the backslash that escapes one)
// ends the comment somewhere the app never intended.

it('scrubs an app name that would corrupt the header', function (string $appName, string $expected): void {
    config()->set('app.name', $appName);

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    expect(lastRagnoUserAgent())->toBe($expected);
})->with([
    'CRLF' => ["Acme\r\nX-Injected: 1", 'laravel-ragno (Acme X-Injected: 1)'],
    'NUL' => ["Acme\x00Books", 'laravel-ragno (Acme Books)'],
    'DEL' => ["Acme\x7FBooks", 'laravel-ragno (Acme Books)'],
    'tabs and runs of spaces' => ["  Acme\t\t Books  ", 'laravel-ragno (Acme Books)'],
    'parens of its own' => ['Acme (Books)', 'laravel-ragno (Acme Books)'],
    'trailing backslash' => ['Acme\\', 'laravel-ragno (Acme)'],
    'backslash escaping a paren' => ['Acme \\) Books', 'laravel-ragno (Acme Books)'],
    'nothing but control bytes' => ["\r\n\t", 'laravel-ragno'],
    'non-ascii survives' => ['Acme Böcker', 'laravel-ragno (Acme Böcker)'],
]);

it('clips a long app name', function (): void {
    config()->set('app.name', str_repeat('Acme ', 40));

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    expect(lastRagnoUserAgent())
        ->toBe('laravel-ragno ('.mb_trim(str_repeat('Acme ', 13)).')')
        ->and(mb_strlen(lastRagnoUserAgent()))->toBeLessThanOrEqual(80);
});
