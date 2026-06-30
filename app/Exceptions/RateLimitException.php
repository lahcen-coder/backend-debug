<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when an AI provider returns HTTP 429 Too Many Requests.
 *
 * The queue job catches this exception separately and uses $this->release()
 * to requeue the job after the provider's retry-after period, rather than
 * burning a retry attempt.
 */
class RateLimitException extends AIProviderException
{
    /** Suggested number of seconds to wait before retrying. */
    private int $retryAfter;

    public function __construct(string $message = '', int $retryAfter = 60, \Throwable $previous = null)
    {
        parent::__construct($message ?: "AI provider rate limit exceeded. Retry after {$retryAfter}s.", previous: $previous);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
