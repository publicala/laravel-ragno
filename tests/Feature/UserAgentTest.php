<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Publicala\Ragno\Facades\Ragno;

// What reaches the wire. The composition itself is covered in
// tests/Unit/UserAgentTest.php; these pin the two values the driver feeds it
// (Composer's installed version, `app.name`) and the overrides that bypass both.

it('sends the driver version and the app name from the environment', function (): void {
    config()->set('app.name', 'Acme Books');

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    $version = (string) InstalledVersions::getPrettyVersion('publicala/laravel-ragno');

    expect($version)->not->toBe('')
        // Whatever this checkout resolves to (`dev-main` here, `1.2.3` from a
        // tag) has to reach the header, in the product token's own alphabet.
        ->and(lastRagnoUserAgent())->toMatch('#^laravel-ragno/[A-Za-z0-9._+~-]+ \(Acme Books\)$#')
        ->and(lastRagnoUserAgent())->toContain(mb_ltrim($version, 'v'));
});

it('drops the comment when the app has no name', function (): void {
    config()->set('app.name', '');

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    expect(lastRagnoUserAgent())->toMatch('#^laravel-ragno/[A-Za-z0-9._+~-]+$#');
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
