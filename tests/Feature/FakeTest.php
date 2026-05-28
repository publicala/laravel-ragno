<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Facades\Ragno;

it('fakes a service, returns rows, and records the query', function (): void {
    Ragno::fake(['primary' => [['id' => '1', 'name' => 'Acme']]]);

    $rows = DB::connection('primary')->select('select * from tenants where id = 1');

    expect($rows[0]->name)->toBe('Acme');

    Ragno::assertQueried('primary', fn (string $sql): bool => str_contains($sql, 'tenants'));
});

it('asserts nothing was queried', function (): void {
    Ragno::fake();

    Ragno::assertNothingQueried();
});

it('returns an empty result set for un-stubbed services', function (): void {
    Ragno::fake(['primary' => [['id' => '1']]]);

    config()->set('database.connections.analytics', [
        'driver' => 'ragno',
        'ragno_service' => 'analytics',
        'ragno_token' => 'test-token',
    ]);

    expect(DB::connection('analytics')->select('select 1'))->toBe([]);
});

it('records queries for later inspection', function (): void {
    Ragno::fake(['primary' => []]);

    DB::connection('primary')->select('select 1');
    DB::connection('primary')->select('select 2');

    expect(Ragno::recorded('primary'))->toHaveCount(2)
        ->and(Ragno::recorded())->toHaveCount(2); // all services
});

it('accepts a raw Http response as a fake value', function (): void {
    Ragno::fake([
        'primary' => Http::response(ragnoEnvelope([['id' => '99']])),
    ]);

    expect(DB::connection('primary')->select('select 1')[0]->id)->toBe('99');
});

it('asserts nothing was queried for a specific service', function (): void {
    Ragno::fake(['primary' => [['id' => '1']]]);

    DB::connection('primary')->select('select 1');

    Ragno::assertQueried('primary');
    Ragno::assertNothingQueried('analytics');
});

it('ignores non-ragno requests when asserting a service was queried', function (): void {
    Ragno::fake(['primary' => [['id' => '1']]]);
    // A second fake stub for an unrelated URL so the global preventStrayRequests
    // does not block our side call.
    Http::fake(['example.com/*' => Http::response('ok')]);

    Http::get('https://example.com/ping');
    DB::connection('primary')->select('select 1');

    // The side request exercises the early-return path inside the matcher.
    Ragno::assertQueried('primary');
});
