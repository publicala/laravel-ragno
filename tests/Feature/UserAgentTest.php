<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Publicala\Ragno\Facades\Ragno;
use Publicala\Ragno\UserAgent;

// What reaches the wire. The composition itself is covered in
// tests/Unit/UserAgentTest.php; these pin the two values the driver feeds it
// (Composer's installed version, `app.name`) and the overrides that bypass both.

it('sends the driver version and the app name from the environment', function (): void {
    config()->set('app.name', 'Acme Books');

    Ragno::fake();

    DB::connection('primary')->select('select 1');

    $version = InstalledVersions::getPrettyVersion('publicala/laravel-ragno');

    // Whatever this checkout resolves to has to be the version that reaches the
    // header, as a product token. The value itself is out of the suite's hands
    // (`dev-main` here, `dev-<branch>` elsewhere, `1.2.3` from a tag), so assert
    // against the composition of it rather than restating the token rules, which
    // tests/Unit/UserAgentTest.php pins on their own.
    expect($version)->not->toBeNull()
        ->and(lastRagnoUserAgent())->toBe(UserAgent::compose($version, 'Acme Books'))
        ->and(lastRagnoUserAgent())->toMatch('#^laravel-ragno/[A-Za-z0-9._+~-]+ \(Acme Books\)$#');
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
