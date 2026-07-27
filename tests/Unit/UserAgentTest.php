<?php

declare(strict_types=1);

use Publicala\Ragno\UserAgent;

it('names the driver, its version, and the app', function (): void {
    expect(UserAgent::compose('1.2.3', 'Acme Books'))->toBe('laravel-ragno/1.2.3 (Acme Books)');
});

// Releases are tagged `vX.Y.Z`, and a branch install reaches the driver as
// `dev-<branch>` where git allows characters a product token does not.

it('normalizes the version into a product token', function (?string $version, string $expected): void {
    expect(UserAgent::compose($version, 'Acme Books'))->toBe($expected);
})->with([
    'tag prefix' => ['v1.2.3', 'laravel-ragno/1.2.3 (Acme Books)'],
    'no prefix' => ['1.2.3', 'laravel-ragno/1.2.3 (Acme Books)'],
    'pre-release' => ['v1.2.3-beta.1', 'laravel-ragno/1.2.3-beta.1 (Acme Books)'],
    'build metadata' => ['1.2.3+no-version-set', 'laravel-ragno/1.2.3+no-version-set (Acme Books)'],
    'branch' => ['dev-main', 'laravel-ragno/dev-main (Acme Books)'],
    'branch with a slash' => ['dev-feature/reads', 'laravel-ragno/dev-featurereads (Acme Books)'],
    'branch with a paren' => ['dev-feature(2)', 'laravel-ragno/dev-feature2 (Acme Books)'],
    'unknown' => [null, 'laravel-ragno (Acme Books)'],
    'empty' => ['', 'laravel-ragno (Acme Books)'],
    'nothing usable' => ['v', 'laravel-ragno (Acme Books)'],
]);

// `APP_NAME` is free text. A control byte in a header value makes the HTTP
// client reject the request, and a paren (or the backslash that escapes one)
// ends the comment somewhere the app never intended.

it('scrubs an app name that would corrupt the header', function (string $appName, string $expected): void {
    expect(UserAgent::compose('1.2.3', $appName))->toBe($expected);
})->with([
    'CRLF' => ["Acme\r\nX-Injected: 1", 'laravel-ragno/1.2.3 (Acme X-Injected: 1)'],
    'NUL' => ["Acme\x00Books", 'laravel-ragno/1.2.3 (Acme Books)'],
    'DEL' => ["Acme\x7FBooks", 'laravel-ragno/1.2.3 (Acme Books)'],
    'tabs and runs of spaces' => ["  Acme\t\t Books  ", 'laravel-ragno/1.2.3 (Acme Books)'],
    'parens of its own' => ['Acme (Books)', 'laravel-ragno/1.2.3 (Acme Books)'],
    'trailing backslash' => ['Acme\\', 'laravel-ragno/1.2.3 (Acme)'],
    'backslash escaping a paren' => ['Acme \\) Books', 'laravel-ragno/1.2.3 (Acme Books)'],
    'non-ascii survives' => ['Acme Böcker', 'laravel-ragno/1.2.3 (Acme Böcker)'],
]);

it('drops the comment when the app has no name', function (string $appName): void {
    expect(UserAgent::compose('1.2.3', $appName))->toBe('laravel-ragno/1.2.3');
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'nothing but control bytes' => "\r\n\t",
]);

it('sends the bare product name when it knows neither version nor app', function (): void {
    expect(UserAgent::compose(null, ''))->toBe('laravel-ragno');
});

it('clips a long app name', function (): void {
    $header = UserAgent::compose('1.2.3', str_repeat('Acme ', 40));

    expect($header)->toBe('laravel-ragno/1.2.3 ('.mb_trim(str_repeat('Acme ', 13)).')')
        ->and(mb_strlen($header))->toBeLessThanOrEqual(86);
});
