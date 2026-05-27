<?php

declare(strict_types=1);

namespace Publicala\Ragno;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Publicala\Ragno\Console\PingCommand;

final class RagnoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ragno.php', 'ragno');

        $this->app->singleton('ragno', function (Application $app): RagnoManager {
            return new RagnoManager(
                (string) $app->make(ConfigRepository::class)->get('ragno.base_url', 'https://data.publica.la'),
            );
        });

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

        $client = new RagnoClient(
            $this->httpFactory(),
            (string) ($config['ragno_base_url'] ?? $shared['base_url'] ?? 'https://data.publica.la'),
            (string) ($config['ragno_service'] ?? $name),
            (string) ($config['ragno_token'] ?? ''),
            (int) ($config['ragno_timeout'] ?? $shared['timeout'] ?? 30),
            (int) ($config['ragno_connect_timeout'] ?? $shared['connect_timeout'] ?? 10),
            (string) ($config['ragno_user_agent'] ?? $shared['user_agent'] ?? 'laravel-ragno'),
        );

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
