<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when a carrier call fails authentication or signature verification.
 * RetryPolicy will NOT retry AuthException — the credentials are wrong and
 * retrying is spam.
 *
 * @api
 */
class AuthException extends LocalizedException
{
}
