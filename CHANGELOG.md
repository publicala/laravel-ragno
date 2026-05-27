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
- Tested on Laravel 12 and 13; PHPStan level 8; Pint (Laravel preset).
