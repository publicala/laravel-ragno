<?php

declare(strict_types=1);

namespace Publicala\Ragno\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Publicala\Ragno\Facades\Ragno;
use Throwable;

/**
 * Verify connectivity and the token for Ragno-backed connections by running a
 * trivial `SELECT 1`. Handy right after wiring up a migration:
 *
 *   php artisan ragno:ping            # all driver=ragno connections
 *   php artisan ragno:ping primary   # one connection
 */
final class PingCommand extends Command
{
    protected $signature = 'ragno:ping {connection? : A specific connection name; defaults to every driver=ragno connection}';

    protected $description = 'Verify connectivity and the token for Ragno-backed database connections.';

    public function handle(DatabaseManager $db, ConfigRepository $config): int
    {
        $connections = $this->resolveConnections($config);

        if ($connections === []) {
            $this->components->warn('No connections with driver=ragno found in config/database.php.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($connections as $name) {
            $start = microtime(true);

            try {
                $db->connection($name)->selectOne('SELECT 1 AS ok');
                $ms = (int) round((microtime(true) - $start) * 1000);
                $this->components->info(sprintf('%s — OK (%d ms)', $name, $ms));
            } catch (Throwable $e) {
                $failed = true;
                $requestId = Ragno::requestId($e);
                $this->components->error(sprintf(
                    '%s — FAILED: %s%s',
                    $name,
                    $e->getMessage(),
                    $requestId !== null ? " [request_id: {$requestId}]" : '',
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveConnections(ConfigRepository $config): array
    {
        $requested = $this->argument('connection');

        if (is_string($requested) && $requested !== '') {
            return [$requested];
        }

        $connections = [];

        /** @var array<string, array<string, mixed>> $all */
        $all = (array) $config->get('database.connections', []);

        foreach ($all as $name => $connection) {
            if (($connection['driver'] ?? null) === 'ragno') {
                $connections[] = $name;
            }
        }

        return $connections;
    }
}
