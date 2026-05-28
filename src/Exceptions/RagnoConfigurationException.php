<?php

declare(strict_types=1);

namespace Publicala\Ragno\Exceptions;

use Illuminate\Contracts\Validation\Validator;
use LogicException;

/**
 * Thrown when a Ragno connection's entry in `config/database.php` is invalid —
 * e.g. a service name that isn't a clean URL slug, or a base URL that isn't an
 * absolute http(s) URL.
 *
 * Surfaces the first time the connection is resolved, before any HTTP request
 * leaves the app. This is an operator error (a misconfigured connection), not
 * a runtime query failure — hence {@see LogicException}.
 */
final class RagnoConfigurationException extends LogicException
{
    public static function invalid(string $connection, Validator $validator): self
    {
        $reason = $validator->errors()->all();

        return new self(
            "Ragno connection [{$connection}] is misconfigured: ".implode(' ', $reason)
        );
    }
}
