# Changelog

All notable changes to `publicala/laravel-ragno`.

The top `## ` header is always the most recent version — `## vX.Y.Z`, exact —
and that header is the source of truth the release workflow reads. Adding a
new top block to this file is what cuts a release.

## v0.7.1

### Changed

- **Pest 5 in the dev toolchain.** `pestphp/pest` and `pestphp/pest-plugin-type-coverage` accept `^4.0|^5.0`, so the Laravel 13 matrix leg resolves Pest 5 (PHPUnit 13) while the Laravel 12 leg stays on Pest 4. The remaining dev-tool constraints reference bare majors.
- **CI checks out with `actions/checkout` v7** (pinned at v7.0.1).
- **The pre-push hook is gone.** PHPStan, type coverage, and the test suite run in CI behind the required gate. The local pre-push pass duplicated that enforcement, added push latency, and hard-failed in environments without dev dependencies installed. Pre-commit formatting hooks are unchanged.

## v0.7.0

### Changed

- **The `User-Agent` names the driver's version.** The default header was
  `laravel-ragno (Acme Books)`, which left the gateway unable to tell which
  driver version a client runs. It now leads with a product token carrying the
  version Composer installed the package at, read from Composer's runtime
  metadata: `laravel-ragno/0.7.0 (Acme Books)` from a release tag,
  `laravel-ragno/dev-main (...)` from a branch, and the bare `laravel-ragno` when
  no version can be determined. `RAGNO_USER_AGENT` and a per-connection
  `ragno_user_agent` still replace the whole header, unchanged.
- **`composer-runtime-api` is a declared requirement.** Reading the installed
  version uses `Composer\InstalledVersions`, so `^2.0` is now required
  explicitly. Composer 2 has shipped it since 2020 and Composer 1 cannot install
  this package's PHP requirement anyway, so no install that works today breaks.

### Added

- **`Publicala\Ragno\UserAgent`.** Header composition moved out of the service
  provider into one class that takes a version and an app name and returns the
  header. Scrubbing lives there: a version is reduced to a product token's
  alphabet (the `v` on a tag goes, as do the characters git allows in a branch
  name but a token does not), and an app name keeps its comment intact (control
  bytes, DEL, parens, and backslashes stripped, clipped at 64 characters).

## v0.6.0

### Changed

- **The `User-Agent` names your app.** Requests went out as plain
  `laravel-ragno`, which told the gateway's audit log only that some Laravel app
  made the read. The default now carries `config('app.name')` alongside the
  driver's name, e.g. `laravel-ragno (Acme Books)`. The app name lands verbatim
  in a request header, so control bytes, parens, and backslashes are stripped
  from it and it is clipped at 64 characters. `RAGNO_USER_AGENT` and a per-connection
  `ragno_user_agent` still replace the whole header, unchanged. If you published
  `config/ragno.php` before this release, drop the `'laravel-ragno'` default from
  its `user_agent` line (`env('RAGNO_USER_AGENT')`) to pick the app name up.

## v0.5.2

### Fixed

- **Connections now report their own name.** A `driver=ragno` connection
  resolved through Laravel's `db.extend` path never had the `name` config key
  stamped onto it. Laravel only does that inside
  `ConnectionFactory::parseConfig()`, which the driver-extension path bypasses.
  As a result `Connection::getName()` returned `null` and every `QueryExecuted`
  event carried a blank connection name, so the query log, Telescope, Nightwatch
  and any other `QueryExecuted` consumer showed Ragno reads with no connection.
  `RagnoServiceProvider` now stamps the connection name onto the config
  (mirroring `parseConfig()`'s `Arr::add`), so `getName()` and all query events
  report the configured connection name. No configuration or API change.

## v0.5.1

### Changed

- **The repository is public.** Installs need no authentication. Removed the
  private-repository Composer auth instructions from the README. The package
  is still GitHub VCS only (never Packagist), so the `repositories` entry plus
  `composer require publicala/laravel-ragno` is all consumers need.

## v0.5.0

### Added

- **Private-repository install guide.** The README documents Composer
  authentication for the GitHub-only distribution. A single fine-grained
  token, shared across the org, is supplied through `COMPOSER_AUTH` (or
  `composer config --global github-oauth.github.com`). Covers local
  machines, GitHub Actions, and Laravel Cloud, Vapor, and Forge.

### Changed

- **Neutral example connection names.** Documentation and tests use
  `primary` and `analytics` as the illustrative database connections, with
  `Acme Books` as the sample tenant. No runtime behavior changes. The package
  never hard-codes a connection name, so existing `config/database.php`
  entries are unaffected.

## v0.4.0

### Added

- **HTTPS-only base URL in production.** `RagnoServiceProvider` now requires
  `ragno_base_url` to be an absolute `https://` URL when
  `app()->environment('production')` is true. Outside production (local dev,
  CI, container gateways) `http://` is still accepted — same as v0.3.0. The
  failure mode is the same `RagnoConfigurationException` raised at connection
  resolve, before any HTTP request leaves the app.
- **Token control-character sanitization.** `ragno_token` is now rejected at
  config time if it contains any byte in `\x00-\x1F` or `\x7F` (CR, LF, TAB,
  NUL, DEL, etc.). Defense against header injection via a hostile `.env`
  value when Laravel builds `Authorization: Bearer <token>`. Legitimate
  Sanctum / PASETO tokens use only printable ASCII and are unaffected. An
  empty token still resolves the connection so the runtime `missing_token`
  error keeps surfacing with its friendlier message at query time.

### Notes

- A production deployment with an `http://` `ragno_base_url` will start
  throwing on first connection resolve. The recommended fix is to switch the
  URL to `https://` — the gateway has always required TLS on production
  traffic; this just makes the misconfiguration visible at boot.
- The validator's error messages now mention scheme and control-character
  requirements explicitly, so the operator gets actionable feedback in the
  `RagnoConfigurationException`.

## v0.3.0

### Added

- `RagnoConfigurationException` — thrown the first time a misconfigured
  `driver=ragno` connection is resolved, before any HTTP request leaves the
  app. Carries the connection name and the validator's reasons.
- `RagnoServiceProvider` now validates each connection's `ragno_service` and
  `ragno_base_url` via `Validator::make` (`alpha_dash:ascii` and
  `url:http,https`). Both values land directly in the gateway request URL,
  so rejecting non-slug services and non-http(s) base URLs at the config
  seam is fast, legible defense-in-depth alongside the package's SELECT-only
  GRANT (which remains the security boundary).

### Notes

- Pre-existing connections whose service name was not a URL slug (contained
  `.`, `/`, whitespace, etc.) will now throw on resolve. The README and
  existing fixtures already used slug-style names, so this should not
  affect any well-formed configuration.

## v0.2.0

### Added

- Full Publica.la quality stack: Pint (Laravel preset + strict rules), Rector
  (Laravel + prepared sets), PHPStan level 8 with Larastan, Lefthook
  (pre-commit Rector + Pint, pre-push PHPStan + type coverage + tests), Pest
  with **type coverage and code coverage both at 100%**.
- Architecture tests (`tests/Arch/`): strict types enforced in source and
  namespaced support; every source class `final`; no `dd` / `dump` /
  `var_dump` / `Log::debug` in source; exceptions implement `Throwable`.
- `tests/CLAUDE.md` documenting test conventions and the faking story.
- CHANGELOG-driven release automation (`.github/workflows/release.yml`):
  push or workflow_dispatch on `main` reads the top `## vX.Y.Z` header,
  finds the commit that introduced it, creates the tag and the GitHub
  Release with the extracted notes. Idempotent.
- CI moved to `depot-ubuntu-24.04` with `publicala/php-ci-static@v1`;
  matrix across Laravel 12 and 13 with PCOV for coverage.

### Internal

No public API change. Source-side changes are Rector / Pint output plus a few
coverage-oriented refactors:

- `RagnoConnection`: native `array` / `Generator` return types on
  `select` / `cursor`; the explicit `getPdo` / `getReadPdo` overrides were
  dropped so the closure-sentinel resolver is reached via the parent path
  (covered by a single test).
- `ReadOnlyStatementGuard`: rewrote the byte scanner with `isset($sql[$i])`
  instead of `strlen()` so it stays byte-correct regardless of
  `mb_str_functions` / Str-helper rewrites.
- `RagnoManager`: typed the request callback parameter to satisfy type
  coverage.

## v0.1.0

### Added

- `ragno` database driver: a read-only `RagnoConnection` that routes
  Eloquent / query-builder / raw reads through the Ragno SQL-over-HTTP
  gateway.
- `RagnoClient` — bearer-authenticated HTTP client for one service; maps the
  error envelope (with `request_id` / `X-Request-Id` fallback) and preserves
  the underlying transport exception.
- Read-only enforcement: writes, transactions, and non-read statements throw
  `ReadOnlyViolationException`; a local `ReadOnlyStatementGuard` fast-fails
  obvious non-reads before they leave the app.
- Numeric-as-string wire contract preserved end to end.
- `Ragno` facade with `fake()` / `assertQueried()` / `assertNothingQueried()`
  / `recorded()` test helpers and `requestId()` / `exceptionFrom()`
  accessors.
- `ragno:ping` artisan command to verify connectivity and tokens.

## Versioning

Semver, on `## vX.Y.Z` exactly (no suffixes, no `[Unreleased]`).

- **Patch (`vX.Y.Z+1`)** — bug fixes, internal refactors, docs, dev-tooling
  tweaks. No behavioural change for consumers.
- **Minor (`vX.Y+1.0`)** — additive features (new public methods, new config
  keys with safe defaults, new artisan commands). No breaking change.
- **Major (`vX+1.0.0`)** — breaking changes: removed or renamed public API,
  changed default behaviour that could surprise consumers, dropped Laravel
  or PHP versions.
