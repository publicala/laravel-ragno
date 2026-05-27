<?php

declare(strict_types=1);

namespace Publicala\Ragno\Exceptions;

use LogicException;

/**
 * Thrown when code asks a Ragno connection to do something it cannot, by
 * design: write, open a transaction, or run a non-read statement.
 *
 * This is a programming error (a read-only connection used as if it were
 * read-write), not a runtime query failure — hence {@see LogicException}.
 * Ragno is a read-only gateway; there is no PDO and no write grant behind it.
 */
final class ReadOnlyViolationException extends LogicException
{
    public static function write(string $operation): self
    {
        return new self(
            "Ragno is a read-only gateway: [{$operation}] is not supported. ".
            'Reads only — there is no write grant behind this connection.'
        );
    }

    public static function transaction(string $operation): self
    {
        return new self(
            "Ragno is a read-only gateway: transactions are not supported (called [{$operation}]). ".
            'Each query is an independent, stateless HTTP request; there is nothing to commit or roll back.'
        );
    }

    public static function statement(string $keyword): self
    {
        $shown = $keyword === '' ? '(empty)' : $keyword;

        return new self(
            'Ragno is a read-only gateway: only SELECT / WITH / SHOW / DESCRIBE / EXPLAIN '.
            "are allowed, but this statement starts with [{$shown}]."
        );
    }

    public static function multipleStatements(): self
    {
        return new self(
            'Ragno is a read-only gateway: multiple statements in a single query are not allowed.'
        );
    }
}
