<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Publicala\Ragno\Exceptions\RagnoQueryException;
use Publicala\Ragno\RagnoClient;

/**
 * The client is unit-tested against its own HTTP Factory instance (faked in
 * place), independent of the facade wiring.
 */
function ragnoClient(Factory $http, string $token = 'tok'): RagnoClient
{
    return new RagnoClient($http, 'https://data.publica.la', 'primary', $token);
}

it('parses a success envelope into stdClass rows', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->response(ragnoEnvelope([['id' => '1', 'n' => 'x']]))]);

    $rows = ragnoClient($http)->query('select 1');

    expect($rows[0])->toBeInstanceOf(stdClass::class)
        ->and($rows[0]->id)->toBe('1')
        ->and($rows[0]->n)->toBe('x');
});

it('maps an error envelope to a RagnoQueryException with code, request_id and status', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->response([
        'error' => ['code' => 'query_error', 'message' => 'bad sql', 'request_id' => 'req-9'],
    ], 400)]);

    $thrown = null;
    try {
        ragnoClient($http)->query('selct 1');
    } catch (RagnoQueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe('bad sql')
        ->and($thrown->errorCode)->toBe('query_error')
        ->and($thrown->requestId)->toBe('req-9')
        ->and($thrown->httpStatus)->toBe(400);
});

it('wraps a transport failure as transport_error, preserving the previous exception', function (): void {
    $http = new Factory;
    $http->fake(fn () => throw new ConnectionException('boom'));

    $thrown = null;
    try {
        ragnoClient($http)->query('select 1');
    } catch (RagnoQueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->errorCode)->toBe('transport_error')
        ->and($thrown->getPrevious())->toBeInstanceOf(ConnectionException::class);
});

it('falls back to the X-Request-Id header when the body lacks request_id', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->response(null, 500, ['X-Request-Id' => 'hdr-7'])]);

    $thrown = null;
    try {
        ragnoClient($http)->query('select 1');
    } catch (RagnoQueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->requestId)->toBe('hdr-7')
        ->and($thrown->httpStatus)->toBe(500);
});

it('fails fast when no token is configured', function (): void {
    ragnoClient(new Factory, token: '')->query('select 1');
})->throws(RagnoQueryException::class, 'No Ragno token');
