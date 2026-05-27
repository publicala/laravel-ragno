<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Publicala\Ragno\Facades\Ragno;

it('fakes a service, returns rows, and records the query', function (): void {
    Ragno::fake(['primary' => [['id' => '1', 'name' => 'Acme']]]);

    $rows = DB::connection('primary')->select('select * from tenants where id = 1');

    expect($rows[0]->name)->toBe('Acme');

    Ragno::assertQueried('primary', fn (string $sql) => str_contains($sql, 'tenants'));
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

    expect(Ragno::recorded('primary'))->toHaveCount(2);
});
