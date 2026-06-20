<?php

declare(strict_types=1);

namespace Publicala\Ragno;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Publicala\Ragno\Console\PingCommand;
use Publicala\Ragno\Exceptions\RagnoConfigurationException;

final class RagnoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ragno.php', 'ragno');

        $this->app->singleton('ragno', fn (Application $app): RagnoManager => new RagnoManager(
            (string) $app->make(ConfigRepository::class)->get('ragno.base_url', 'https://data.publica.la'),
        ));

        // Register the read-only `ragno` database driver. A connection in
        // config/database.php with driver=ragno routes its reads through the
        // gateway. Resolved lazily, only when the connection is first used.
        $this->app->resolving('db', function (DatabaseManager $db, Application $app): void {
            $db->extend('ragno', fn (array $config, string $name): RagnoConnection => $this->makeConnection($app, $config, $name));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ragno.php' => $this->app->configPath('ragno.php'),
            ], 'ragno-config');

            $this->commands([PingCommand::class]);
        }
    }

    /**
     * Build a Ragno connection from its config/database.php entry, falling back
     * to the shared config/ragno.php defaults for transport-level settings.
     *
     * @param  array<string, mixed>  $config
     */
    private function makeConnection(Application $app, array $config, string $name): RagnoConnection
    {
        /** @var array<string, mixed> $shared */
        $shared = (array) $app->make(ConfigRepository::class)->get('ragno', []);

        $baseUrl = (string) ($config['ragno_base_url'] ?? $shared['base_url'] ?? 'https://data.publica.la');
        $service = (string) ($config['ragno_service'] ?? $name);
        $token = (string) ($config['ragno_token'] ?? '');

        $this->validateConnectionConfig($name, $baseUrl, $service, $token);

        $client = new RagnoClient(
            $this->httpFactory(),
            $baseUrl,
            $service,
            $token,
            (int) ($config['ragno_timeout'] ?? $shared['timeout'] ?? 30),
            (int) ($config['ragno_connect_timeout'] ?? $shared['connect_timeout'] ?? 10),
            (string) ($config['ragno_user_agent'] ?? $shared['user_agent'] ?? 'laravel-ragno'),
        );

        // Stamp the connection's own name onto its config. Laravel only does
        // this inside ConnectionFactory::parseConfig() (Arr::add($config,
        // 'name', $name)), which the `db.extend` driver path bypasses entirely.
        // Without it Connection::getName() returns null, so every QueryExecuted
        // event carries a blank connection name (the query log, Telescope and
        // Nightwatch then show these reads with no connection).
        $config['name'] ??= $name;
        $config['enforce_read_only'] ??= $shared['enforce_read_only'] ?? true;
        $config['max_rows'] ??= $shared['max_rows'] ?? null;

        return new RagnoConnection(
            $client,
            (string) ($config['database'] ?? $config['ragno_service'] ?? $name),
            (string) ($config['prefix'] ?? ''),
            $config,
        );
    }

    /**
     * Fail fast at the config seam on a misconfigured Ragno connection.
     *
     * Three things land directly in outbound HTTP:
     *   - `ragno_service` is concatenated into the gateway path
     *     (`/api/v1/db/<service>/query`), so it must be a clean URL slug.
     *   - `ragno_base_url` becomes the request's host/scheme; an `http://` URL
     *     in production downgrades a bearer token to plaintext on the wire, so
     *     we require `https://` exactly there and allow `http://` only outside
     *     production (Herd, container dev gateways, etc.).
     *   - `ragno_token` is sent verbatim in the `Authorization: Bearer …`
     *     header. A `\r` / `\n` / NUL / DEL slipped in via `.env` would let an
     *     attacker who controls that env value inject additional HTTP headers;
     *     also rejects `\t` and the rest of `\x00-\x1F` for the same reason.
     *     Empty tokens pass through so the runtime `missing_token` check at
     *     query time still fires with its friendly message.
     *
     * Per-service SELECT-only GRANTs remain the security boundary; this is the
     * fast, legible fail in front of them.
     */
    private function validateConnectionConfig(string $name, string $baseUrl, string $service, string $token): void
    {
        $allowedSchemes = $this->app->environment('production') ? 'https' : 'http,https';

        $validator = Validator::make(
            [
                'ragno_service' => $service,
                'ragno_base_url' => $baseUrl,
                'ragno_token' => $token,
            ],
            [
                'ragno_service' => ['required', 'string', 'alpha_dash:ascii'],
                'ragno_base_url' => ['required', 'string', 'url:'.$allowedSchemes],
                // `string` (not `required`) so an empty token still resolves the
                // connection and yields `missing_token` at query time.
                'ragno_token' => ['string', 'not_regex:/[\x00-\x1F\x7F]/'],
            ],
            [
                'ragno_base_url.url' => $this->app->environment('production')
                    ? 'The ragno_base_url must be an absolute https:// URL in production (got a non-https or malformed value).'
                    : 'The ragno_base_url must be an absolute http:// or https:// URL.',
                'ragno_token.not_regex' => 'The ragno_token must not contain control characters (CR, LF, TAB, NUL, DEL, etc.). Re-issue the token without whitespace or newlines.',
            ],
        );

        if ($validator->fails()) {
            throw RagnoConfigurationException::invalid($name, $validator);
        }
    }

    /**
     * The HTTP client factory the driver issues requests through. We resolve
     * the `Http` facade root (not a fresh container instance) so a consumer's
     * `Http::fake()` / `Ragno::fake()` intercepts the driver in tests.
     */
    private function httpFactory(): HttpFactory
    {
        /** @var HttpFactory $factory */
        $factory = Http::getFacadeRoot();

        return $factory;
    }
}
