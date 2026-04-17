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
 * Thrown when an upstream carrier returns a non-success HTTP status. Callers
 * (RetryPolicy) classify the status code to decide whether to retry.
 *
 * @api
 */
class TransientHttpException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        private readonly int $statusCode = 0,
        ?\Throwable $cause = null,
        int $code = 0,
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Factory with explicit status code + message.
     */
    public static function create(int $statusCode, string $message): self
    {
        return new self(new Phrase($message), $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
