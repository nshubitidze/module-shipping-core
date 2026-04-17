<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown when a carrier returns HTTP 429 (Too Many Requests). If the carrier
 * provided a Retry-After header, {@see getRetryAfterSeconds()} returns its
 * integer value; otherwise NULL and RetryPolicy applies its own backoff.
 *
 * @api
 */
class RateLimitedException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        private readonly ?int $retryAfterSeconds = null,
        ?\Throwable $cause = null,
        int $code = 0,
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Factory with explicit retry-after + message.
     */
    public static function create(?int $retryAfterSeconds, string $message): self
    {
        return new self(new Phrase($message), $retryAfterSeconds);
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
