# Changelog

All notable changes to `publicala/laravel-ragno`.

The top `## ` header is always the most recent version — `## vX.Y.Z`, exact —
and that header is the source of truth the release workflow reads. Adding a
new top block to this file is what cuts a release.

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
