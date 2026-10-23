<?php
declare(strict_types=1);

namespace Library\Qontak;

/**
 * Error raised by the Qontak integration. `$reason` is a stable machine code
 * (auth_failed, forbidden, rate_limited, timeout, unavailable, not_found,
 * validation, key_missing, ...). Messages are safe to show to an admin: they
 * never contain credentials, signatures or raw response bodies.
 */
class QontakException extends \RuntimeException
{
    /** @var string */
    public $reason;
    /** @var int */
    public $httpStatus;
    /** @var int|null seconds, from Retry-After on 429 */
    public $retryAfter;

    public function __construct(string $message, string $reason = 'error', int $httpStatus = 0, $retryAfter = null)
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->httpStatus = $httpStatus;
        $this->retryAfter = $retryAfter;
    }

    /** Worth retrying later (journal sync, recording sync, AI). */
    public function isTransient(): bool
    {
        return in_array($this->reason, ['rate_limited', 'timeout', 'unavailable'], true);
    }
}
