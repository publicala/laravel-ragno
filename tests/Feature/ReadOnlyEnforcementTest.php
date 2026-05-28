<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Exceptions\ReadOnlyViolationException;

beforeEach(function (): void {
    // Reads in the "allowed" cases resolve to an empty result set; writes never
    // reach the network because they throw before any request is built.
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([]))]);
});

it('refuses write operations', function (string $method, array $args): void {
    DB::connection('primary')->{$method}(...$args);
})->throws(ReadOnlyViolationException::class)->with([
    'insert' => ['insert', ['insert into t (a) values (?)', [1]]],
    'update' => ['update', ['update t set a = ?', [1]]],
    'delete' => ['delete', ['delete from t']],
    'statement' => ['statement', ['create table t (id int)']],
    'affectingStatement' => ['affectingStatement', ['update t set a = 1']],
    'unprepared' => ['unprepared', ['truncate t']],
]);

it('refuses transaction operations', function (string $method, array $args): void {
    DB::connection('primary')->{$method}(...$args);
})->throws(ReadOnlyViolationException::class)->with([
    'beginTransaction' => ['beginTransaction', []],
    'commit' => ['commit', []],
    'rollBack' => ['rollBack', []],
    'transaction' => ['transaction', [fn (): null => null]],
]);

it('rejects a write disguised as a read', function (): void {
    DB::connection('primary')->select('delete from t');
})->throws(ReadOnlyViolationException::class);

it('rejects multiple statements in one query', function (): void {
    DB::connection('primary')->select('select 1; drop table t');
})->throws(ReadOnlyViolationException::class);

it('allows the documented read keywords', function (string $sql): void {
    DB::connection('primary')->select($sql);

    expect(true)->toBeTrue();
})->with([
    'plain select' => 'select * from tenants',
    'leading line comment' => "-- a comment\nselect 1",
    'leading block comment' => '/* hi */ select 1',
    'wrapped union' => '(select 1) union (select 2)',
    'CTE' => 'WITH x AS (select 1) select * from x',
    'show' => 'SHOW TABLES',
    'describe' => 'DESCRIBE tenants',
    'desc' => 'DESC tenants',
    'explain' => 'EXPLAIN select 1',
    'semicolon inside a string' => "select ';' as semi",
    'lone trailing semicolon' => 'select 1;',
]);

it('can be disabled per connection (gateway still enforces server-side)', function (): void {
    config()->set('database.connections.unguarded', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => 'test-token',
        'enforce_read_only' => false,
    ]);

    // With the local guard off, a non-read statement is sent to the gateway
    // (which would reject it) instead of failing locally — proving the toggle.
    DB::connection('unguarded')->select('delete from t');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/db/primary/query'));
});
