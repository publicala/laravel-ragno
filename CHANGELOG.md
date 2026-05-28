# Changelog

All notable changes to `publicala/laravel-ragno` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ragno` database driver: a read-only `RagnoConnection` that routes Eloquent /
  query-builder / raw reads through the Ragno SQL-over-HTTP gateway.
- `RagnoClient` — bearer-authenticated HTTP client for one service; maps the
  error envelope (with `request_id` / `X-Request-Id` fallback) and preserves the
  underlying transport exception.
- Read-only enforcement: writes, transactions, and non-read statements throw
  `ReadOnlyViolationException`; a local `ReadOnlyStatementGuard` fast-fails
  obvious non-reads before they leave the app.
- Numeric-as-string wire contract preserved end to end.
- `Ragno` facade with `fake()` / `assertQueried()` / `assertNothingQueried()` /
  `recorded()` test helpers and `requestId()` / `exceptionFrom()` accessors.
- `ragno:ping` artisan command to verify connectivity and tokens.
- Full Publica.la quality stack: Pint (Laravel preset + strict rules), Rector
  (Laravel + prepared sets), PHPStan level 8 with Larastan, Lefthook
  (pre-commit Rector+Pint, pre-push PHPStan + type-coverage + tests),
  CI matrix across Laravel 12 and 13.
- Architecture tests (`tests/Arch/`): strict types enforced in source and
  namespaced support; every source class final; no `dd` / `dump` / `var_dump`
  / `Log::debug` in source; exceptions implement `Throwable`.
- Quality gates: **100% type coverage**, **100% line coverage**.
