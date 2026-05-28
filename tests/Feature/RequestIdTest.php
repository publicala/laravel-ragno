<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Exceptions\RagnoQueryException;
use Publicala\Ragno\Facades\Ragno;

it('digs the request_id out of a wrapped QueryException', function (): void {
    Http::fake(['data.publica.la/*' => Http::response([
        'error' => ['code' => 'query_error', 'message' => 'unknown column', 'request_id' => 'req-42'],
    ], 400)]);

    $thrown = null;
    try {
        DB::connection('primary')->select('select nope from tenants');
    } catch (QueryException $queryException) {
        $thrown = $queryException;
    }

    // Laravel wraps our RagnoQueryException in a QueryException...
    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($thrown->getPrevious())->toBeInstanceOf(RagnoQueryException::class);

    // ...and the helper recovers the gateway metadata from the chain.
    expect(Ragno::requestId($thrown))->toBe('req-42')
        ->and(Ragno::exceptionFrom($thrown)?->errorCode)->toBe('query_error');
});

it('returns null when there is no Ragno exception in the chain', function (): void {
    expect(Ragno::requestId(new RuntimeException('unrelated')))->toBeNull();
});

it('reads the request_id directly off a RagnoQueryException', function (): void {
    $e = new RagnoQueryException('boom', errorCode: 'driver_error', requestId: 'req-7');

    expect(Ragno::requestId($e))->toBe('req-7');
});
