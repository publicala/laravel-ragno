<?php

declare(strict_types=1);

namespace Publicala\Ragno\Tests;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;
use Publicala\Ragno\RagnoServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RagnoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('ragno.base_url', 'https://data.publica.la');

        $app->make(Repository::class)->set('database.connections.primary', [
            'driver' => 'ragno',
            'ragno_service' => 'primary',
            'ragno_token' => 'test-token',
        ]);
    }
}
