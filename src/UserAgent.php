<?php

declare(strict_types=1);

namespace Publicala\Ragno;

use Illuminate\Support\Str;

/**
 * Composes the `User-Agent` the driver sends to the gateway.
 *
 * The gateway's audit log attributes traffic by what it is told, so the header
 * names the driver, the version it runs, and the app making the read:
 *
 *   laravel-ragno/0.7.0 (Acme Books)
 *
 * Both halves arrive as free text (a git branch reaches the version as
 * `dev-<branch>`, the app name is whatever `APP_NAME` says) and the result lands
 * verbatim in a request header, so each is scrubbed to what its own position
 * allows.
 */
final class UserAgent
{
    private const string PRODUCT = 'laravel-ragno';

    /**
     * How much of the app name to send: long enough for a real name, short
     * enough that a runaway `APP_NAME` cannot dominate the header.
     */
    private const int APP_NAME_LIMIT = 64;

    /**
     * The header for the given driver version and app name. Either may be
     * unknown: with no version the product token stands alone, with no app name
     * the comment is dropped.
     */
    public static function compose(?string $version, string $appName): string
    {
        $product = self::productToken($version);
        $comment = self::comment($appName);

        return $comment === '' ? $product : $product.' '.$comment;
    }

    /**
     * `laravel-ragno/0.7.0`, or the bare name when no version is known.
     *
     * Releases are tagged with a `v` prefix that a product token does not want,
     * and a git ref's alphabet is wider than a version's, so the rest goes.
     */
    private static function productToken(?string $version): string
    {
        $version = (string) Str::of((string) $version)
            ->ltrim('v')
            ->replaceMatches('/[^A-Za-z0-9._+~-]+/', '');

        return $version === '' ? self::PRODUCT : self::PRODUCT.'/'.$version;
    }

    /**
     * The app's name as an HTTP comment, or an empty string when it has none.
     *
     * A header value accepts neither control bytes nor DEL, so an `APP_NAME`
     * carrying one would make the HTTP client reject every query outright.
     * Parens go too, along with the backslash that escapes one: either can end
     * the comment somewhere the app never intended (a trailing `\` escapes the
     * closing paren and leaves it unterminated).
     */
    private static function comment(string $appName): string
    {
        $appName = (string) Str::of($appName)
            ->replaceMatches('/[\x00-\x1F\x7F()\\\\]+/', ' ')
            ->squish()
            ->limit(self::APP_NAME_LIMIT, '');

        return $appName === '' ? '' : "({$appName})";
    }
}
