<?php
declare(strict_types=1);

namespace Library\Hubspot;

/**
 * Error raised by the HubSpot integration. `$reason` is a stable machine code:
 *   validation, forbidden, not_configured, auth_failed, missing_scopes,
 *   rate_limited, timeout, unavailable, not_found, conflict,
 *   key_missing, key_invalid, decrypt_failed, encrypt_failed.
 * Messages are safe to show to an admin: they never contain the access token,
 * Authorization headers or raw response bodies.
 */
class HubspotException extends \RuntimeException
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

    /** Worth retrying later (journal sync). */
    public function isTransient(): bool
    {
        return in_array($this->reason, ['rate_limited', 'timeout', 'unavailable'], true);
    }
}
