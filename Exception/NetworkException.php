<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when a carrier call fails at the transport layer — DNS failure,
 * connection reset, socket timeout. RetryPolicy treats all NetworkException
 * instances as retryable.
 *
 * @api
 */
class NetworkException extends LocalizedException
{
}
