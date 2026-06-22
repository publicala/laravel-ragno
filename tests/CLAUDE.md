# tests/

Pest v4. **`it()` syntax only**, never class-based PHPUnit. Every test file declares `declare(strict_types=1)`.

## Layout

| Dir | What lives here |
| --- | --- |
| `Arch/` | Architecture rules: strict types everywhere, final classes, no `dd/dump/var_dump`, exceptions are throwable. |
| `Feature/` | Driver behaviour through Laravel: `RagnoConnection`, `RagnoClient`, the read-only enforcement, inline-bindings golden tests, the `Ragno` facade fakes, `ragno:ping`, and the request_id helper. |
| `Unit/` | Pure logic with no framework: the `ReadOnlyStatementGuard` scanner. |
| `Fixtures/` | Throwaway Eloquent models / helpers shared across tests. |
| `Pest.php` | Global config (see below). |
| `TestCase.php` | Orchestra Testbench harness; defines the default `primary` connection. |

## Global behaviour (`tests/Pest.php`)

Every test runs with these guard rails set in `beforeEach`:

- `Str::createRandomStringsNormally()` / `Str::createUuidsNormally()` — no deterministic-string traps.
- `Http::preventStrayRequests()` — any HTTP call without a matching `Http::fake()` / `Ragno::fake()` stub fails the test loudly.
- `Process::preventStrayProcesses()` — same idea for `Process::run`.
- `Sleep::fake()` — `sleep()` / `usleep()` calls don't wall-clock.
- `freezeTime()` — `now()` doesn't drift mid-test.

There is no database in this package (`pest` does not refresh anything; we do not use `RefreshDatabase`). The "primary" connection in `TestCase` is a Ragno connection, not a SQL store.

## Faking the gateway

`Http::fake()` already intercepts the driver (the connection resolves the `Http` facade's factory). `Ragno::fake()` is sugar that spells the gateway URL and envelope for you. Prefer it in tests over hand-rolling URLs:

```php
Ragno::fake([
    'primary' => [['id' => '1', 'name' => 'Acme']],
]);

DB::connection('primary')->select('select * from tenants');

Ragno::assertQueried('primary', fn (string $sql) => str_contains($sql, 'tenants'));
```

A few non-obvious bits:

- `Http::fake()` **merges** stub callbacks across calls in the same test — calling it twice with the same URL does not replace the first stub. Set every stub you need in one call, or split into separate tests.
- An un-stubbed Ragno service hit while `Ragno::fake()` is active returns an empty envelope (the `*/query` catch-all), so a stray query in a test fails with surprising-but-deterministic empty rows rather than a network error.
- The numeric-as-string contract is real: build envelopes with **string** values for numeric columns (`['id' => '1']`, not `['id' => 1]`). The envelope helper `ragnoEnvelope()` in `Pest.php` already shapes the `request_id` / `service` / `data` keys; the rows are yours.

## Running

```bash
composer test:unit                  # plain Pest run
composer test:types                 # PHPStan level 8
composer test:type-coverage         # Pest type coverage, must be 100%
composer test:lint                  # Pint --test + Rector --dry-run
composer test:coverage              # Pest code coverage, must be 100%
composer test                       # Everything above
```

`composer test:coverage` and `composer test` need a coverage driver (PCOV or Xdebug). CI installs PCOV via `shivammathur/setup-php`. Locally on Herd:

```bash
php -d zend_extension="/Applications/Herd.app/Contents/Resources/xdebug/xdebug-84-arm64.so" \
    -d xdebug.mode=coverage \
    vendor/bin/pest --coverage --min=100
```

## What 100% coverage means here

Both **type coverage** (Pest type-coverage plugin) and **line coverage** are gated at 100%. New code without tests will fail the build. When you add a branch that is genuinely unreachable, prefer redesigning the API to make it reachable over silencing coverage — the package treats unreachable code as a smell, not a fact of life.
