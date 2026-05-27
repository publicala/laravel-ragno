<?php

declare(strict_types=1);

namespace Publicala\Ragno\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void fake(array<string, mixed> $services = [])
 * @method static void assertQueried(string $service, ?\Closure $callback = null)
 * @method static void assertNothingQueried(?string $service = null)
 * @method static \Illuminate\Support\Collection<int, mixed> recorded(?string $service = null)
 * @method static ?string requestId(\Throwable $e)
 * @method static ?\Publicala\Ragno\Exceptions\RagnoQueryException exceptionFrom(\Throwable $e)
 *
 * @see \Publicala\Ragno\RagnoManager
 */
final class Ragno extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ragno';
    }
}
