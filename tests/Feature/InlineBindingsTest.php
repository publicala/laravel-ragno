<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([]))]);
});

it('inlines an integer binding as a numeric literal', function (): void {
    DB::connection('primary')->table('users')->where('id', 5)->get();

    expect(lastRagnoQuery())->toBe('select * from `users` where `id` = 5');
});

it('quotes and escapes a string binding', function (): void {
    DB::connection('primary')->table('users')->where('name', "O'Brien")->get();

    expect(lastRagnoQuery())->toBe("select * from `users` where `name` = 'O\\'Brien'");
});

it('inlines whereIn lists', function (): void {
    DB::connection('primary')->table('u')->whereIn('status', ['a', 'b'])->get();

    expect(lastRagnoQuery())->toBe("select * from `u` where `status` in ('a', 'b')");
});

it('keeps a literal question mark inside a string value', function (): void {
    DB::connection('primary')->table('u')->where('q', 'a?b')->get();

    expect(lastRagnoQuery())->toBe("select * from `u` where `q` = 'a?b'");
});

it('inlines booleans and nulls', function (): void {
    DB::connection('primary')->table('u')->where('active', true)->whereNull('deleted_at')->get();

    expect(lastRagnoQuery())->toBe('select * from `u` where `active` = 1 and `deleted_at` is null');
});

it('inlines a date binding using the connection date format', function (): void {
    DB::connection('primary')
        ->table('u')
        ->where('created_at', '>', Carbon::parse('2026-01-02 03:04:05'))
        ->get();

    expect(lastRagnoQuery())->toContain("`created_at` > '2026-01-02 03:04:05'");
});

it('escapes backslashes and newlines', function (): void {
    DB::connection('primary')->table('u')->where('path', "a\\b\nc")->get();

    expect(lastRagnoQuery())->toBe("select * from `u` where `path` = 'a\\\\b\\nc'");
});
