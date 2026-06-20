<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MultipleColumnsSelectedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Publicala\Ragno\Exceptions\RagnoQueryException;
use Publicala\Ragno\RagnoConnection;
use Publicala\Ragno\Tests\Fixtures\RagnoTestTenant;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('resolves a RagnoConnection for the ragno driver', function (): void {
    expect(DB::connection('primary'))->toBeInstanceOf(RagnoConnection::class);
});

it('reports its configured connection name', function (): void {
    // Laravel stamps the `name` config key only inside ConnectionFactory, which
    // the db.extend driver path bypasses; without our fix getName() is null.
    expect(DB::connection('primary')->getName())->toBe('primary');
});

it('labels query events with the connection name', function (): void {
    // The symptom the name fix guards against: a null connectionName makes the
    // query log, Telescope, Nightwatch, etc. show Ragno reads with no connection.
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([]))]);

    $connectionNames = [];
    DB::listen(function (QueryExecuted $query) use (&$connectionNames): void {
        $connectionNames[] = $query->connectionName;
    });

    DB::connection('primary')->select('select 1');

    expect($connectionNames)->toBe(['primary']);
});

it('returns rows as stdClass and preserves numeric strings', function (): void {
    Http::fake([
        'data.publica.la/api/v1/db/primary/query' => Http::response(ragnoEnvelope([
            ['id' => '9007199255042522', 'price' => '19.90', 'name' => 'Acme'],
        ])),
    ]);

    $rows = DB::connection('primary')->select('select * from tenants');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBeInstanceOf(stdClass::class)
        ->and($rows[0]->id)->toBe('9007199255042522') // BIGINT past 2^53 stays a string
        ->and($rows[0]->price)->toBe('19.90')          // every numeric is a string
        ->and($rows[0]->name)->toBe('Acme');
});

it('selectOne returns the first row', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['id' => '1'], ['id' => '2']]))]);

    expect(DB::connection('primary')->selectOne('select * from t')->id)->toBe('1');
});

it('selectOne returns null for an empty result set', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([]))]);

    expect(DB::connection('primary')->selectOne('select * from t'))->toBeNull();
});

it('scalar returns the single selected value', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['c' => '42']]))]);

    expect(DB::connection('primary')->scalar('select count(*) as c from t'))->toBe('42');
});

it('scalar keeps the framework MultipleColumnsSelectedException semantics', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['a' => '1', 'b' => '2']]))]);

    DB::connection('primary')->scalar('select a, b from t');
})->throws(MultipleColumnsSelectedException::class);

it('cursor yields each buffered row', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['id' => '1'], ['id' => '2']]))]);

    $ids = [];
    foreach (DB::connection('primary')->cursor('select * from t') as $row) {
        $ids[] = $row->id;
    }

    expect($ids)->toBe(['1', '2']);
});

it('does not hit the gateway while pretending', function (): void {
    Http::fake();

    DB::connection('primary')->pretend(fn ($connection) => $connection->select('select * from t'));

    Http::assertNothingSent();
});

it('throws when the result set exceeds max_rows', function (): void {
    config()->set('database.connections.capped', [
        'driver' => 'ragno',
        'ragno_service' => 'primary',
        'ragno_token' => 'test-token',
        'max_rows' => 1,
    ]);

    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['id' => '1'], ['id' => '2']]))]);

    DB::connection('capped')->select('select * from t');
})->throws(RagnoQueryException::class, 'exceeding the configured max_rows');

it('hydrates Eloquent models through the gateway', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([
        ['id' => '7', 'name' => 'Acme'],
    ]))]);

    $tenant = RagnoTestTenant::query()->find(7);

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Acme')
        ->and($tenant->id)->toBe(7); // string '7' from the wire, cast to int by the model
});

it('sends the bearer token and JSON content type', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([]))]);

    DB::connection('primary')->select('select 1');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('Accept', 'application/json'));
});

it('has no PDO behind the connection', function (): void {
    DB::connection('primary')->getPdo();
})->throws(RuntimeException::class, 'no PDO');

it('has no read PDO behind the connection', function (): void {
    DB::connection('primary')->getReadPdo();
})->throws(RuntimeException::class, 'no PDO');

it('refuses to embed binary values', function (): void {
    DB::connection('primary')->escape("\x00\x01", binary: true);
})->throws(RuntimeException::class, 'Binary');
