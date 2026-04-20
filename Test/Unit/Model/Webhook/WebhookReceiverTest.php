<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Webhook;

use Laminas\Http\Headers;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Dto\DispatchResult;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Webhook\WebhookDispatcher;
use Shubo\ShippingCore\Model\Webhook\WebhookReceiver;

/**
 * Unit tests for {@see WebhookReceiver}. Covers the three responsibilities
 * of the REST entrypoint: body cap + truncation log, log symmetry with the
 * frontend controller, and webapi-exception mapping of dispatcher results.
 */
class WebhookReceiverTest extends TestCase
{
    private const CARRIER_CODE = 'wolt';

    /** @var WebhookDispatcher&MockObject */
    private WebhookDispatcher $dispatcher;

    /** @var RestRequest&MockObject */
    private RestRequest $request;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    /** @var list<array{event:string, context:array<string,mixed>}> */
    private array $loggedEvents = [];

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(WebhookDispatcher::class);
        $this->request = $this->createMock(RestRequest::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->loggedEvents = [];
        $this->logger->method('logWebhook')->willReturnCallback(
            function (string $event, array $context = []): void {
                $this->loggedEvents[] = ['event' => $event, 'context' => $context];
            },
        );

        $this->request->method('getHeaders')->willReturn(new Headers());
    }

    public function testReceiveTruncatesOversizedBodyAndLogsTruncation(): void
    {
        $oversized = str_repeat('a', WebhookDispatcher::MAX_RAW_BODY_BYTES + 512);
        $this->request->method('getContent')->willReturn($oversized);

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                self::CARRIER_CODE,
                self::callback(
                    static fn (string $body): bool =>
                        strlen($body) === WebhookDispatcher::MAX_RAW_BODY_BYTES,
                ),
                self::isType('array'),
            )
            ->willReturn(DispatchResult::accepted('evt-1'));

        $receiver = $this->receiver();
        $result = $receiver->receive(self::CARRIER_CODE);

        self::assertSame(DispatchResult::STATUS_ACCEPTED, $result);
        $truncationLog = $this->findLoggedEvent('webhook_rest_body_truncated');
        self::assertNotNull(
            $truncationLog,
            'Oversized body must emit webhook_rest_body_truncated.',
        );
        self::assertSame(self::CARRIER_CODE, $truncationLog['context']['carrier_code']);
        self::assertSame(
            WebhookDispatcher::MAX_RAW_BODY_BYTES + 512,
            $truncationLog['context']['original_size'],
        );
        self::assertSame(
            WebhookDispatcher::MAX_RAW_BODY_BYTES,
            $truncationLog['context']['capped_size'],
        );
    }

    public function testReceiveDoesNotLogTruncationForUnderCapBody(): void
    {
        $this->request->method('getContent')->willReturn('{"ok":true}');

        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::accepted('evt-2'));

        $receiver = $this->receiver();
        $receiver->receive(self::CARRIER_CODE);

        self::assertNull(
            $this->findLoggedEvent('webhook_rest_body_truncated'),
            'Under-cap body must not emit webhook_rest_body_truncated.',
        );
    }

    public function testReceiveLogsWebhookReceivedOnAcceptedDispatch(): void
    {
        $this->request->method('getContent')->willReturn('{"ok":true}');
        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::accepted('evt-3'));

        $receiver = $this->receiver();
        $result = $receiver->receive(self::CARRIER_CODE);

        self::assertSame(DispatchResult::STATUS_ACCEPTED, $result);
        $log = $this->findLoggedEvent('webhook_received');
        self::assertNotNull($log, 'webhook_received must be logged for symmetry with the frontend controller.');
        self::assertSame(self::CARRIER_CODE, $log['context']['carrier_code']);
        self::assertSame(DispatchResult::STATUS_ACCEPTED, $log['context']['dispatch_status']);
        self::assertSame('evt-3', $log['context']['external_event_id']);
        self::assertSame('rest', $log['context']['entrypoint']);
    }

    public function testReceiveMapsUnknownCarrierToWebapiNotFound(): void
    {
        $this->request->method('getContent')->willReturn('{}');
        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::unknownCarrier());

        $receiver = $this->receiver();

        try {
            $receiver->receive('does-not-exist');
            self::fail('WebapiException was expected for unknown carrier.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_NOT_FOUND, $e->getHttpCode());
        }
    }

    public function testReceiveMapsRejectedToWebapiBadRequest(): void
    {
        $this->request->method('getContent')->willReturn('{}');
        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::rejected('signature_invalid'));

        $receiver = $this->receiver();

        try {
            $receiver->receive(self::CARRIER_CODE);
            self::fail('WebapiException was expected for rejected dispatch.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_BAD_REQUEST, $e->getHttpCode());
        }
    }

    public function testReceiveLogsUnhandledExceptionAndRethrows(): void
    {
        $this->request->method('getContent')->willReturn('{}');
        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('boom'));

        $receiver = $this->receiver();

        try {
            $receiver->receive(self::CARRIER_CODE);
            self::fail('RuntimeException from dispatcher must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $log = $this->findLoggedEvent('webhook_unhandled_exception');
        self::assertNotNull(
            $log,
            'Dispatcher exception must be recorded before it propagates.',
        );
        self::assertSame(self::CARRIER_CODE, $log['context']['carrier_code']);
        self::assertSame(\RuntimeException::class, $log['context']['exception_class']);
        self::assertSame('boom', $log['context']['exception_message']);
        self::assertSame('rest', $log['context']['entrypoint']);
    }

    public function testReceiveReturnsDuplicateStatusStringForDuplicateDispatch(): void
    {
        $this->request->method('getContent')->willReturn('{}');
        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::duplicate('evt-dup'));

        $receiver = $this->receiver();

        self::assertSame(
            DispatchResult::STATUS_DUPLICATE,
            $receiver->receive(self::CARRIER_CODE),
        );
    }

    private function receiver(): WebhookReceiver
    {
        return new WebhookReceiver(
            $this->dispatcher,
            $this->request,
            $this->logger,
        );
    }

    /**
     * @return array{event:string, context:array<string,mixed>}|null
     */
    private function findLoggedEvent(string $event): ?array
    {
        foreach ($this->loggedEvents as $entry) {
            if ($entry['event'] === $event) {
                return $entry;
            }
        }
        return null;
    }
}
