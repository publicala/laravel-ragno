<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('reports OK for a healthy ragno connection', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['ok' => '1']]))]);

    $this->artisan('ragno:ping', ['connection' => 'primary'])
        ->expectsOutputToContain('primary — OK')
        ->assertSuccessful();
});

it('reports a failure with the request_id when the gateway rejects', function (): void {
    Http::fake(['data.publica.la/*' => Http::response([
        'error' => ['code' => 'driver_error', 'message' => 'connection refused', 'request_id' => 'req-99'],
    ], 500)]);

    $this->artisan('ragno:ping', ['connection' => 'primary'])
        ->expectsOutputToContain('req-99')
        ->assertFailed();
});

it('pings every ragno connection when none is named', function (): void {
    Http::fake(['data.publica.la/*' => Http::response(ragnoEnvelope([['ok' => '1']]))]);

    $this->artisan('ragno:ping')
        ->expectsOutputToContain('primary — OK')
        ->assertSuccessful();
});

it('warns when there are no ragno connections', function (): void {
    config()->set('database.connections.primary', [
        'driver' => 'mysql',
    ]);

    $this->artisan('ragno:ping')
        ->expectsOutputToContain('No connections with driver=ragno')
        ->assertSuccessful();
});
