<?php
declare(strict_types=1);

namespace Library\Freshdesk;

/**
 * Error raised by the Freshdesk integration. `$reason` is a stable machine code
 * (auth_failed, forbidden, rate_limited, timeout, unavailable, not_found,
 * conflict, validation, key_missing, ...). Messages are safe to show to an
 * admin: they never contain the API key, headers or raw response bodies.
 */
class FreshdeskException extends \RuntimeException
{
    /** @var string */
    public $reason;
    /** @var int */
    public $httpStatus;
    /** @var int|null seconds, from Retry-After on 429 */
    public $retryAfter;
    /** @var array Freshdesk error items [{field, code}] (no values) */
    public $errors;

    public function __construct(string $message, string $reason = 'error', int $httpStatus = 0, $retryAfter = null, array $errors = [])
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->httpStatus = $httpStatus;
        $this->retryAfter = $retryAfter;
        $this->errors = $errors;
    }

    /** Worth retrying later (journal sync, recording upload). */
    public function isTransient(): bool
    {
        return in_array($this->reason, ['rate_limited', 'timeout', 'unavailable'], true);
    }

    /** True when Freshdesk reported a missing (mandatory) field. */
    public function hasMissingField(): bool
    {
        foreach ($this->errors as $e) {
            if (($e['code'] ?? '') === 'missing_field') {
                return true;
            }
        }
        return false;
    }
}
