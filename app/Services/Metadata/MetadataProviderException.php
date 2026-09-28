<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use RuntimeException;

/**
 * Base for provider failures, so jobs can react to "rate limited, retry in N
 * seconds" without knowing which provider they talk to.
 */
class MetadataProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $rateLimited = false,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
