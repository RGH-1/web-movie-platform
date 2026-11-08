<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class TmdbRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $upstreamStatus = null,
        public readonly ?string $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
