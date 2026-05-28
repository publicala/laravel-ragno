<?php

declare(strict_types=1);

namespace Publicala\Ragno;

use Closure;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use PDO;
use Publicala\Ragno\Exceptions\RagnoQueryException;
use Publicala\Ragno\Exceptions\ReadOnlyViolationException;
use Publicala\Ragno\Query\ReadOnlyStatementGuard;
use RuntimeException;
use stdClass;

/**
 * A read-only Laravel database connection that routes every read through the
 * Ragno HTTP gateway instead of a PDO socket.
 *
 * The query builder and Eloquent are untouched: they generate MySQL-dialect
 * SQL (so we use the MySQL grammar and processor), we inline the bindings
 * (Ragno takes raw SQL, no bindings), and hand the statement to
 * {@see RagnoClient}.
 *
 * Read-only is structural, not a setting: there is no PDO behind this
 * connection and every write, transaction, and non-read statement throws a
 * {@see ReadOnlyViolationException}.
 */
final class RagnoConnection extends Connection
{
    private readonly bool $enforceReadOnly;

    private readonly ?int $maxRows;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly RagnoClient $client,
        string $database = '',
        string $tablePrefix = '',
        array $config = [],
    ) {
        // There is no PDO behind this connection. We hand the parent a resolver
        // that throws if anything ever tries to build one; it stays non-null so
        // reconnectIfMissingConnection() never attempts a (re)connect, and
        // getPdo()/getReadPdo() below make any real use loud.
        parent::__construct(
            fn (): PDO => throw new RuntimeException('RagnoConnection has no PDO; all reads go through the HTTP gateway.'),
            $database,
            $tablePrefix,
            $config,
        );

        $this->enforceReadOnly = (bool) ($config['enforce_read_only'] ?? true);
        $this->maxRows = isset($config['max_rows']) ? (int) $config['max_rows'] : null;
    }

    /**
     * Run a SELECT through Ragno. Mirrors Connection::select's contract:
     * returns an array of rows (as stdClass, like PDO's FETCH_OBJ).
     *
     * `selectOne()`, `scalar()`, and `selectFromWriteConnection()` all delegate
     * to this in the base Connection, so they work unchanged — including
     * `scalar()`'s MultipleColumnsSelectedException semantics.
     *
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<string, mixed>  $fetchUsing
     * @return array<int, stdClass>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        // Guard before run() so a misuse surfaces as ReadOnlyViolationException
        // rather than being wrapped in a QueryException by runQueryCallback().
        if ($this->enforceReadOnly) {
            ReadOnlyStatementGuard::assert($query);
        }

        /** @var array<int, stdClass> $rows */
        $rows = $this->run($query, $bindings, function (string $query, array $bindings): array {
            if ($this->pretending()) {
                return [];
            }

            return $this->client->query($this->inlineBindings($query, $bindings));
        });

        if ($this->maxRows !== null && count($rows) > $this->maxRows) {
            throw new RagnoQueryException(
                sprintf('Ragno returned %d rows, exceeding the configured max_rows of %d.', count($rows), $this->maxRows),
                errorCode: 'max_rows_exceeded',
            );
        }

        return $rows;
    }

    /**
     * Ragno returns the full result set in one response; there is no
     * server-side cursor, so we buffer and yield. Lazy callers keep working,
     * just not lazily.
     *
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<string, mixed>  $fetchUsing
     * @return Generator<int, stdClass>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        foreach ($this->select($query, $bindings, $useReadPdo) as $row) {
            yield $row;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function statement($query, $bindings = []): never
    {
        throw ReadOnlyViolationException::write('statement');
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function affectingStatement($query, $bindings = []): never
    {
        throw ReadOnlyViolationException::write('affectingStatement');
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function insert($query, $bindings = []): never
    {
        throw ReadOnlyViolationException::write('insert');
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function update($query, $bindings = []): never
    {
        throw ReadOnlyViolationException::write('update');
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function delete($query, $bindings = []): never
    {
        throw ReadOnlyViolationException::write('delete');
    }

    /** {@inheritDoc} */
    public function unprepared($query): never
    {
        throw ReadOnlyViolationException::write('unprepared');
    }

    /** {@inheritDoc} */
    public function transaction(Closure $callback, $attempts = 1): never
    {
        throw ReadOnlyViolationException::transaction('transaction');
    }

    /** {@inheritDoc} */
    public function beginTransaction(): never
    {
        throw ReadOnlyViolationException::transaction('beginTransaction');
    }

    /** {@inheritDoc} */
    public function commit(): never
    {
        throw ReadOnlyViolationException::transaction('commit');
    }

    /** {@inheritDoc} */
    public function rollBack($toLevel = null): never
    {
        throw ReadOnlyViolationException::transaction('rollBack');
    }

    protected function getDefaultQueryGrammar(): MySqlGrammar
    {
        return new MySqlGrammar($this);
    }

    protected function getDefaultPostProcessor(): MySqlProcessor
    {
        return new MySqlProcessor;
    }

    /**
     * Escape a string for inline embedding (MySQL/SingleStore dialect). The
     * base implementation defers to PDO::quote(); we have no PDO, so we quote
     * and backslash-escape exactly as the MySQL PDO driver would. The base
     * Connection::escape() already rejects NUL bytes and invalid UTF-8 before
     * reaching here.
     *
     * Caveat: this assumes the default backslash-escaping SQL mode. Under
     * NO_BACKSLASH_ESCAPES, `\'` would not terminate correctly — SingleStore
     * runs the default mode, but do not rely on this as a security control;
     * the SELECT-only GRANT is the boundary.
     *
     * @param  string  $value
     */
    protected function escapeString($value): string
    {
        return "'".str_replace(
            ['\\', "'", '"', "\n", "\r", "\x1a"],
            ['\\\\', "\\'", '\\"', '\\n', '\\r', '\\Z'],
            (string) $value,
        )."'";
    }

    /** {@inheritDoc} */
    protected function escapeBinary($value): string
    {
        throw new RuntimeException('Binary values cannot be embedded in a Ragno query.');
    }

    /**
     * Inline prepared-statement bindings into raw SQL. Ragno accepts no
     * bindings, so the grammar quotes/escapes each value into the statement
     * (ints stay numeric; strings/dates are single-quoted and escaped).
     *
     * @param  array<int|string, mixed>  $bindings
     */
    private function inlineBindings(string $query, array $bindings): string
    {
        return $this->getQueryGrammar()->substituteBindingsIntoRawSql(
            $query,
            $this->prepareBindings($bindings),
        );
    }
}
