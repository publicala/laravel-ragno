<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Publicala\Ragno\Tests\TestCase;

pest()
    ->extend(TestCase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();
        $this->freezeTime();
    })
    ->in(__DIR__);

/**
 * Build a Ragno success envelope around the given rows.
 *
 * @param  array<int, array<string, mixed>>  $data
 * @return array<string, mixed>
 */
function ragnoEnvelope(array $data, string $service = 'primary'): array
{
    return [
        'request_id' => 'req-test',
        'service' => $service,
        'data' => $data,
    ];
}

/**
 * The `query` payload of the most recent request sent to the gateway.
 */
function lastRagnoQuery(): string
{
    $pair = Http::recorded()->last();

    /** @var Request $request */
    $request = $pair[0];

    return (string) ($request->data()['query'] ?? '');
}
