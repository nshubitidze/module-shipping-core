<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Controller\Webhook;

use Laminas\Http\Headers;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Shubo\ShippingCore\Api\Data\Dto\DispatchResult;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Webhook\WebhookDispatcher;

/**
 * Frontend controller for carrier webhook receipt.
 *
 * Mapped to `POST /shubo_shipping/webhook/{carrier_code}` via
 * `etc/frontend/routes.xml`. Server-to-server calls do not go through the
 * form-key flow, so {@see CsrfAwareActionInterface} is implemented to
 * bypass CSRF — same pattern used by Shubo_BogPayment's webhook callback.
 *
 * The controller does the minimum:
 *   - extract and validate the carrier_code URL segment,
 *   - read + cap the raw body at 1 MB,
 *   - copy request headers into a plain array,
 *   - hand off to {@see WebhookDispatcher},
 *   - translate the dispatcher's {@see DispatchResult} into an HTTP code.
 *
 * Carriers retry on 5xx, so unhandled exceptions become 500 "ERROR" and we
 * log full context (trace included). 4xx is a permanent failure from the
 * carrier's perspective; 2xx is terminal success (duplicates included —
 * the carrier should stop retrying).
 */
class Receive implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const PATH_SEGMENT = 'webhook';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly WebhookDispatcher $dispatcher,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        /** @var Raw $raw */
        $raw = $this->rawFactory->create();

        $carrierCode = $this->extractCarrierCode();
        $body = $this->readBody($carrierCode);
        $headers = $this->readHeaders();

        if ($carrierCode === '') {
            $this->logger->logWebhook('webhook_empty_carrier_code', [
                'path_info' => $this->request->getPathInfo(),
                'body_size' => strlen($body),
            ]);
            $raw->setHttpResponseCode(400);
            $raw->setContents('MISSING_CARRIER_CODE');
            return $raw;
        }

        try {
            $result = $this->dispatcher->dispatch($carrierCode, $body, $headers);
            $httpStatus = $this->mapHttpStatus($result->status);

            $this->logger->logWebhook('webhook_received', [
                'carrier_code' => $carrierCode,
                'body_size' => strlen($body),
                'dispatch_status' => $result->status,
                'http_status' => $httpStatus,
                'external_event_id' => $result->externalEventId,
                'reason' => $result->reason,
            ]);

            $raw->setHttpResponseCode($httpStatus);
            $raw->setContents(strtoupper($result->status));
            return $raw;
        } catch (\Throwable $e) {
            $this->logger->logWebhook('webhook_unhandled_exception', [
                'carrier_code' => $carrierCode,
                'body_size' => strlen($body),
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $raw->setHttpResponseCode(500);
            $raw->setContents('ERROR');
            return $raw;
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Accept the request without CSRF validation.
     *
     * Returning `true` here is deliberate: this endpoint receives
     * server-to-server webhook deliveries from carriers (Wolt, Omniva, etc.)
     * which have no session, no form key, and no way to carry a CSRF token.
     * Authenticity is instead established by each handler's own signature /
     * HMAC verification inside {@see WebhookDispatcher::dispatch()}, so
     * skipping Magento's CSRF machinery does not weaken the security model —
     * it just picks the right tool for the transport.
     *
     * Mirrors the pattern already used in Shubo_BogPayment's payment
     * callback controller.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Pull the `{carrier_code}` segment out of the request path. The Magento
     * router maps URL segments to params only when routes declare them, and
     * our route is a bare frontName so we scan the path manually. This is
     * resilient across Magento versions and trims any trailing slashes.
     */
    private function extractCarrierCode(): string
    {
        $path = trim((string)$this->request->getPathInfo(), '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $index = array_search(self::PATH_SEGMENT, $segments, true);
        if ($index === false) {
            return '';
        }

        $next = $index + 1;
        return isset($segments[$next]) ? trim($segments[$next]) : '';
    }

    /**
     * Read the raw request body and cap it at
     * {@see WebhookDispatcher::MAX_RAW_BODY_BYTES}. Prevents a rogue or
     * misconfigured carrier from exhausting memory via a multi-megabyte
     * payload. Emits `webhook_body_truncated` when the cap fires so ops can
     * notice carriers sending oversized deliveries.
     */
    private function readBody(string $carrierCode): string
    {
        $body = (string)$this->request->getContent();
        $originalSize = strlen($body);
        if ($originalSize > WebhookDispatcher::MAX_RAW_BODY_BYTES) {
            $this->logger->logWebhook('webhook_body_truncated', [
                'carrier_code' => $carrierCode,
                'original_size' => $originalSize,
                'capped_size' => WebhookDispatcher::MAX_RAW_BODY_BYTES,
                'entrypoint' => 'frontend',
            ]);
            return substr($body, 0, WebhookDispatcher::MAX_RAW_BODY_BYTES);
        }
        return $body;
    }

    /**
     * Copy request headers into a plain `array<string, string>`. Falls back
     * to an empty array if the underlying laminas Headers is unavailable
     * (defensive against future framework refactors).
     *
     * @return array<string, string>
     */
    private function readHeaders(): array
    {
        $headers = $this->request->getHeaders();
        if (!$headers instanceof Headers) {
            return [];
        }

        /** @var array<string, string|array<int, string>> $raw */
        $raw = $headers->toArray();
        $normalized = [];
        foreach ($raw as $name => $value) {
            if (is_array($value)) {
                $normalized[(string)$name] = implode(', ', array_map('strval', $value));
                continue;
            }
            $normalized[(string)$name] = (string)$value;
        }
        return $normalized;
    }

    private function mapHttpStatus(string $dispatchStatus): int
    {
        return match ($dispatchStatus) {
            DispatchResult::STATUS_ACCEPTED, DispatchResult::STATUS_DUPLICATE => 200,
            DispatchResult::STATUS_UNKNOWN_CARRIER => 404,
            DispatchResult::STATUS_REJECTED => 400,
            default => 500,
        };
    }
}
