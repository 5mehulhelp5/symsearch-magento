<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Exception;

class RateLimitException extends ProviderException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds = 30)
    {
        parent::__construct($message);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
