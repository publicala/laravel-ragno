<?php

declare(strict_types=1);

use Publicala\Ragno\Exceptions\ReadOnlyViolationException;
use Publicala\Ragno\Query\ReadOnlyStatementGuard;

it('detects the leading keyword through comments and parens', function (string $sql, string $keyword): void {
    expect(ReadOnlyStatementGuard::firstKeyword($sql))->toBe($keyword);
})->with([
    ['select 1', 'select'],
    ["  \n select 1", 'select'],
    ['/* c */ SELECT 1', 'select'],
    ["-- c\nWITH x AS (select 1) select 1", 'with'],
    ['(select 1) union (select 2)', 'select'],
    ['DELETE FROM t', 'delete'],
    ['', ''],
]);

it('passes read statements', function (string $sql): void {
    expect(ReadOnlyStatementGuard::passes($sql))->toBeTrue();
})->with([
    'select * from t',
    'WITH x AS (select 1) select * from x',
    'SHOW TABLES',
    'DESCRIBE t',
    'DESC t',
    'EXPLAIN select 1',
    'select 1;',
    "select ';' as x",
    "select 'a;b' as x; ",
    "select 'a\\'b' as x",  // backslash-escaped quote inside a string literal
    "select 'a''b' as x",    // doubled single quote (SQL-style escape)
    "select 'unterminated",  // unterminated string literal — must not crash
]);

it('rejects writes and statement chaining', function (string $sql): void {
    expect(ReadOnlyStatementGuard::passes($sql))->toBeFalse();
})->with([
    'delete from t',
    'update t set a = 1',
    'insert into t values (1)',
    'drop table t',
    'select 1; drop table t',
    'select 1; select 2',
]);

it('throws a descriptive ReadOnlyViolationException on a write', function (): void {
    ReadOnlyStatementGuard::assert('delete from t');
})->throws(ReadOnlyViolationException::class, '[delete]');

it('throws on multiple statements', function (): void {
    ReadOnlyStatementGuard::assert('select 1; select 2');
})->throws(ReadOnlyViolationException::class, 'multiple statements');

it('reports an empty statement clearly', function (): void {
    ReadOnlyStatementGuard::assert('   ');
})->throws(ReadOnlyViolationException::class, '(empty)');
