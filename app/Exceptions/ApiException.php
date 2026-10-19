<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A failure the client can act on, rendered as the standard error envelope.
 * Domain code throws it with a stable machine-readable code.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
