# Laravel Ragno

A read-only Laravel database driver for the **Ragno SQL-over-HTTP protocol**.

Point an Eloquent connection at a Ragno gateway and your models, query builder,
and raw `DB::select()` calls keep working unchanged — every read is routed over
HTTP with a bearer token instead of a database socket. There is no PDO behind
the connection, and writes are impossible by construction.

> Ragno is publica.la's gateway at `data.publica.la`: one HTTP endpoint per
> service, a bearer token scoped to a single read-only SQL grant, and an audited
> request log. This package is the Laravel client for that protocol — it does
> not assume any particular service or schema.

```php
// config/database.php — just another connection
'primary' => [
    'driver' => 'ragno',
    'ragno_service' => env('RAGNO_PRIMARY_SERVICE', 'primary'),
    'ragno_token'   => env('RAGNO_PRIMARY_TOKEN'),
],
```

```php
// Your code does not change.
$tenant = Tenant::on('primary')->find(540);
$rows   = DB::connection('primary')->table('orders')->where('paid', true)->get();
```

---

## Why

The driver hides the awkward, security-sensitive glue of talking to a SQL gateway
over HTTP so you never reimplement it per project:

- **Eloquent & query builder untouched.** MySQL grammar/processor; the SQL your
  app already generates is what gets sent.
- **Read-only by construction.** No PDO; writes, transactions, and non-read
  statements throw a clear exception instead of silently doing nothing.
- **Bindings inlined safely.** Ragno takes raw SQL, so bindings are escaped and
  inlined through Laravel's own grammar — covered by an exhaustive test matrix.
- **Traceable failures.** Every error carries the gateway's `code`, `request_id`,
  and HTTP status.
- **A real testing story.** `Ragno::fake()` (a thin layer over `Http::fake()`)
  means your tests never touch the network.

## Requirements

- PHP **8.4+**
- Laravel **12** or **13**

## Installation

This package is distributed via GitHub only — it is **not** published to
Packagist. Add a VCS repository entry to your app's root `composer.json`,
alongside `require`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/publicala/laravel-ragno" }
]
```

Then — no version constraint needed:

```bash
composer require publicala/laravel-ragno
```

Composer reads the git tags off the VCS source and writes a semver-safe
constraint into your `composer.json` for you (a `^0.x` caret while the
package is on 0.x, then `^1.0` once it tags 1.0), so you always get the
current release without pinning a number by hand. To move to a newer minor
later, run `composer require publicala/laravel-ragno` again (or bump the
caret it wrote).

The service provider and the `Ragno` facade are auto-discovered. Publishing the
config is optional — the connection config below is enough:

```bash
php artisan vendor:publish --tag=ragno-config
```

## Configuration

A Ragno connection is an ordinary `config/database.php` entry with
`driver => 'ragno'`. Define one connection per service; each carries its own
token.

```php
'connections' => [

    'primary' => [
        'driver'        => 'ragno',
        'ragno_service' => env('RAGNO_PRIMARY_SERVICE', 'primary'),
        'ragno_token'   => env('RAGNO_PRIMARY_TOKEN'),
    ],

    'analytics' => [
        'driver'        => 'ragno',
        'ragno_service' => env('RAGNO_ANALYTICS_SERVICE', 'analytics'),
        'ragno_token'   => env('RAGNO_ANALYTICS_TOKEN'),
    ],

],
```

```dotenv
RAGNO_BASE_URL=https://data.publica.la
RAGNO_PRIMARY_TOKEN="…"   # quote it — gateway tokens contain | and other specials
```

Per-connection keys (all optional except the token):

| Key                    | Default                    | Notes                                                   |
| ---------------------- | -------------------------- | ------------------------------------------------------- |
| `ragno_service`        | the connection name        | URL segment: `/api/v1/db/{service}/query`.              |
| `ragno_token`          | —                          | Bearer token. Required; queries fail fast without it.   |
| `ragno_base_url`       | `config('ragno.base_url')` | Override the gateway per connection (e.g. staging).     |
| `ragno_timeout`        | `30`                       | Request timeout (seconds).                              |
| `ragno_connect_timeout`| `10`                       | Connect timeout (seconds).                              |
| `enforce_read_only`    | `true`                     | Local statement guard (see below).                      |
| `max_rows`             | `null`                     | Fail if a result set exceeds this many rows.            |

Shared transport defaults (base URL, timeouts, user agent) live in
`config/ragno.php`.

## Usage

Anything that reads works exactly as it does on a normal connection:

```php
// Eloquent (set $connection on the model, or ->on('primary'))
Tenant::on('primary')->where('active', true)->get();

// Query builder
DB::connection('primary')->table('orders')
    ->whereIn('status', ['paid', 'refunded'])
    ->get();

// Raw reads, including SHOW / DESCRIBE / EXPLAIN
DB::connection('primary')->select('SHOW TABLES');
```

### Read-only by design

There is no write path. These all throw `ReadOnlyViolationException`:

```php
DB::connection('primary')->insert(/* … */);        // and update/delete/statement/unprepared
DB::connection('primary')->beginTransaction();      // and commit/rollBack/transaction()
DB::connection('primary')->select('DELETE FROM …'); // a write smuggled through select()
```

The local guard (`enforce_read_only`) is **defense-in-depth and a nicer error —
not the security boundary.** The boundary is the SELECT-only SQL grant behind
your token, enforced server-side by the gateway. You can disable the local guard
per connection; the gateway still refuses writes.

### Numeric values come back as strings

Ragno returns every numeric column as a JSON **string** (so a BIGINT past
`2^53` survives JSON intact). The driver passes them through untouched — add
Eloquent casts where you want typed values:

```php
protected function casts(): array
{
    return ['id' => 'integer', 'price' => 'decimal:2'];
}
```

### Error handling & `request_id`

A failed query throws Laravel's `QueryException`, wrapping a `RagnoQueryException`.
Recover the gateway metadata with the facade:

```php
use Illuminate\Database\QueryException;
use Publicala\Ragno\Facades\Ragno;

try {
    DB::connection('primary')->select('select * from nope');
} catch (QueryException $e) {
    report("Ragno query failed [{$e->getCode()}] request_id=" . Ragno::requestId($e));

    $ragno = Ragno::exceptionFrom($e); // ?RagnoQueryException
    // $ragno->errorCode, $ragno->requestId, $ragno->httpStatus
}
```

## Testing

The driver speaks HTTP, so `Http::fake()` already intercepts it. `Ragno::fake()`
is sugar that spells the gateway's URL and envelope for you:

```php
use Publicala\Ragno\Facades\Ragno;

Ragno::fake([
    'primary' => [
        ['id' => '540', 'name' => 'Acme Books'],
    ],
]);

$tenant = Tenant::on('primary')->find(540);

expect($tenant->name)->toBe('Acme Books');

Ragno::assertQueried('primary', fn (string $sql) => str_contains($sql, 'tenants'));
Ragno::assertNothingQueried('analytics');
```

Un-stubbed services return an empty result set, so a stray read never escapes to
the network.

## Verifying a connection

```bash
php artisan ragno:ping            # every driver=ragno connection
php artisan ragno:ping primary   # one connection
```

Runs `SELECT 1` and reports latency, or the failure with its `request_id`.

## Limitations

Ragno is a read-only query gateway, not a database connection. By design:

- **No writes, no transactions, no schema builder.** They throw.
- **No server-side cursor.** `cursor()` works but buffers the full result set in
  memory — set `max_rows` as a guard rail for large reads.
- **String escaping assumes the default backslash-escaping SQL mode** (what
  SingleStore runs). It is not a security control; the SELECT-only grant is.
- **Cross-service joins aren't possible** — each token reaches one service. Use
  separate connections and correlate in PHP, or use BI tooling for ad-hoc joins.

## Migrating an existing connection

If a project currently reaches the database with a direct `mysql` / `singlestore`
connection, the switch is small and reversible:

1. Install the package — see [Installation](#installation) for the VCS
   repository entry plus `composer require publicala/laravel-ragno`.
2. Add the gateway keys to the connection and default its driver to `ragno`,
   keeping the old PDO keys as a fallback:

   ```php
   'primary' => [
       'driver' => env('PRIMARY_DRIVER', 'ragno'),

       // Ragno (used when driver=ragno)
       'ragno_service' => env('RAGNO_PRIMARY_SERVICE', 'primary'),
       'ragno_token'   => env('RAGNO_PRIMARY_TOKEN'),

       // Legacy direct PDO (used when PRIMARY_DRIVER=mysql)
       'host'     => env('PRIMARY_DB_HOST'),
       'database' => env('PRIMARY_DB_DATABASE', 'primary'),
       'username' => env('PRIMARY_DB_USERNAME'),
       'password' => env('PRIMARY_DB_PASSWORD'),
       // …
   ],
   ```

3. Pin tests to the legacy driver so they never hit the live gateway —
   `phpunit.xml`: `<env name="PRIMARY_DRIVER" value="mysql"/>` — and use
   `Ragno::fake()` in the tests that exercise the connection.
4. Add casts for any numeric columns you compare or compute on (they now arrive
   as strings).
5. Set `RAGNO_PRIMARY_TOKEN` in each environment and `php artisan ragno:ping`.

Roll back instantly with `PRIMARY_DRIVER=mysql`.

### Not on Laravel?

This package is the Laravel client. The Ragno protocol is just HTTP, so other
runtimes call it directly — e.g. Python:

```python
import requests
r = requests.post(
    "https://data.publica.la/api/v1/db/primary/query",
    headers={"Authorization": f"Bearer {token}"},
    json={"query": "select id, name from tenants limit 10"},
)
rows = r.json()["data"]   # numerics are strings
```

## Quality

This package ships with the Publica.la quality stack and **100% type and code
coverage** as non-negotiable gates:

| Tool | Config | What it does |
|------|--------|--------------|
| Pint | `pint.json` | Laravel preset + strict rules (strict types, immutable dates, ordered class elements, …) |
| Rector | `rector.php` | Laravel sets + prepared sets (dead code, code quality, coding style, type declarations, privatization, early return) |
| PHPStan | `phpstan.neon` | Level 8 with Larastan |
| Pest | `tests/` | `it()` syntax, arch rules in `tests/Arch/`, type coverage and line coverage both at 100% |
| Lefthook | `lefthook.yml` | Pre-commit: Rector + Pint. Pre-push: PHPStan + type coverage + tests. |

Run everything:

```bash
composer install
lefthook install                # one-time, sets up the git hooks
composer test                   # full gate
```

Code coverage (`composer test:coverage` and `composer test`) needs a coverage
driver. CI installs PCOV. Locally on Herd:

```bash
php -d zend_extension="/Applications/Herd.app/Contents/Resources/xdebug/xdebug-84-arm64.so" \
    -d xdebug.mode=coverage \
    vendor/bin/pest --coverage --min=100
```

## License

MIT. See [LICENSE](LICENSE).
