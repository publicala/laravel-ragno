<?php

declare(strict_types=1);

namespace Publicala\Ragno\Query;

use Publicala\Ragno\Exceptions\ReadOnlyViolationException;

/**
 * A fast, local read-only check: the statement must begin with a read keyword
 * and must not chain a second statement after a `;`.
 *
 * This is deliberately a lexical guard, not a SQL parser. It exists to fail
 * fast with a clear error and to keep obvious mistakes from leaving the app —
 * NOT as the security boundary. The boundary is the SQL GRANT behind the Ragno
 * token (SELECT-only) plus the gateway's own read-only guard. Bindings are
 * still placeholders at this point, so they never affect the verdict.
 *
 * The scanner works on bytes (`$sql[$i]` / `isset()`), which is correct for
 * UTF-8: every byte we branch on is ASCII, and UTF-8 multi-byte sequences never
 * contain bytes that collide with SQL syntax. It avoids `strlen()`/`substr()`
 * on purpose so it stays byte-accurate regardless of the active string-function
 * mode.
 */
final class ReadOnlyStatementGuard
{
    /** @var list<string> */
    private const array ALLOWED = ['select', 'with', 'show', 'describe', 'desc', 'explain'];

    /**
     * @throws ReadOnlyViolationException
     */
    public static function assert(string $sql): void
    {
        $keyword = self::firstKeyword($sql);

        if (! in_array($keyword, self::ALLOWED, true)) {
            throw ReadOnlyViolationException::statement($keyword);
        }

        if (self::hasTrailingStatement($sql)) {
            throw ReadOnlyViolationException::multipleStatements();
        }
    }

    public static function passes(string $sql): bool
    {
        return in_array(self::firstKeyword($sql), self::ALLOWED, true)
            && ! self::hasTrailingStatement($sql);
    }

    /**
     * The first SQL keyword, lowercased, skipping leading whitespace, comments,
     * and opening parentheses (so wrapped unions like `(select ...) union ...`
     * and CTEs still resolve to their real leading keyword).
     */
    public static function firstKeyword(string $sql): string
    {
        $i = self::skipInsignificant($sql, 0, skipParens: true);

        $keyword = '';
        while (isset($sql[$i]) && ctype_alpha($sql[$i])) {
            $keyword .= $sql[$i];
            $i++;
        }

        return mb_strtolower($keyword);
    }

    /**
     * True if a `;` is followed by further significant content — i.e. a second
     * statement. A lone trailing `;` (optionally followed by comments) is fine.
     * Semicolons inside string literals, quoted identifiers, and comments are
     * ignored.
     */
    private static function hasTrailingStatement(string $sql): bool
    {
        $i = 0;

        while (isset($sql[$i])) {
            $char = $sql[$i];

            if (in_array($char, ["'", '"', '`'], true)) {
                $i = self::skipQuoted($sql, $i);

                continue;
            }

            if (self::isCommentStart($sql, $i)) {
                $i = self::skipComment($sql, $i);

                continue;
            }

            if ($char === ';') {
                return isset($sql[self::skipInsignificant($sql, $i + 1, skipParens: false)]);
            }

            $i++;
        }

        return false;
    }

    /**
     * Advance past whitespace and comments (and, optionally, opening parens),
     * returning the index of the next significant character.
     */
    private static function skipInsignificant(string $sql, int $i, bool $skipParens): int
    {
        while (isset($sql[$i])) {
            $char = $sql[$i];

            if (ctype_space($char) || ($skipParens && $char === '(')) {
                $i++;

                continue;
            }

            if (self::isCommentStart($sql, $i)) {
                $i = self::skipComment($sql, $i);

                continue;
            }

            break;
        }

        return $i;
    }

    private static function isCommentStart(string $sql, int $i): bool
    {
        $char = $sql[$i] ?? '';
        $next = $sql[$i + 1] ?? '';

        return ($char === '-' && $next === '-')
            || $char === '#'
            || ($char === '/' && $next === '*');
    }

    private static function skipComment(string $sql, int $i): int
    {
        if ($sql[$i] === '/') {
            $i += 2;
            while (isset($sql[$i]) && ! ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
                $i++;
            }

            return isset($sql[$i]) ? $i + 2 : $i;
        }

        // Line comment: -- or #
        while (isset($sql[$i]) && $sql[$i] !== "\n") {
            $i++;
        }

        return $i;
    }

    private static function skipQuoted(string $sql, int $i): int
    {
        $quote = $sql[$i];
        $i++;

        while (isset($sql[$i])) {
            $char = $sql[$i];

            // Backslash escapes apply inside '...' and "..." (MySQL/SingleStore
            // default), not inside backtick identifiers.
            if ($char === '\\' && $quote !== '`') {
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                // A doubled quote ('' or "") is an escaped quote, not the end.
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $i;
    }
}
