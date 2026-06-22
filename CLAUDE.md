# laravel-ragno

Read-only Laravel database driver for the Ragno SQL-over-HTTP gateway. Eloquent and the query builder are untouched. No PDO, no writes.

This is a standalone repository, published on its own and potentially public. Treat everything in it as outside-the-company readable.

## Example and test data: sanctioned placeholders only

Never name a real customer, and never use an internal service codename, in any example, test, fixture, comment, or doc. A real connection name copied from production, or a real tenant in a `->find()` example, is a confidentiality leak that persists in git history long after the line is edited away.

Reach for these placeholders instead. If a name you are about to type is not on this list, it does not belong here:

| Use                       | Placeholder                            |
| ------------------------- | -------------------------------------- |
| Database connections      | `primary`, `analytics`, `reporting`    |
| Sample tenant or customer | `Acme`, `Acme Books`                   |
| Env var prefixes          | `RAGNO_PRIMARY_*`, `RAGNO_ANALYTICS_*` |

When in doubt, use `Acme`. A generic database driver never needs a real name anywhere.

## Pointers

- Tests are Pest `it()` only. See `tests/CLAUDE.md`.
- Releases are CHANGELOG-driven. Adding a new top `## vX.Y.Z` header to `CHANGELOG.md` on `main` is what cuts the tag and release.
- Distribution is GitHub VCS only, never Packagist. See the README install section.
