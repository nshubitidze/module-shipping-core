<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Fake;

use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\Data\Dto\CancelResponse;
use Shubo\ShippingCore\Api\Data\Dto\LabelResponse;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\QuoteResponse;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\Dto\StatusResponse;

/**
 * In-memory fake for {@see CarrierGatewayInterface}. Used in unit tests to
 * exercise the orchestrator/polling/webhook flows without a real carrier.
 *
 * Programmable via {@see setNextError()} / {@see setNextResponse()}. When
 * no override is queued, each method returns a minimal valid DTO.
 *
 * Scope: Phase 3 stabilises the API surface. Phase 4+ tests use this
 * extensively; unit tests in Phase 3 do not exercise the fake directly.
 */
class FakeCarrierGateway implements CarrierGatewayInterface
{
    /** @var array<string, \Throwable> */
    private array $nextError = [];

    /** @var array<string, object> */
    private array $nextResponse = [];

    public function __construct(
        private readonly string $code = 'fake',
    ) {
    }

    /**
     * Queue an exception to throw on the next call to `$op`.
     */
    public function setNextError(string $op, \Throwable $error): void
    {
        $this->nextError[$op] = $error;
    }

    /**
     * Queue a response to return on the next call to `$op`. Caller must
     * pass the correct concrete response DTO for that operation.
     */
    public function setNextResponse(string $op, object $response): void
    {
        $this->nextResponse[$op] = $response;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function quote(QuoteRequest $request): QuoteResponse
    {
        $this->consumeError('quote');
        $override = $this->consumeResponse('quote');
        if ($override instanceof QuoteResponse) {
            return $override;
        }
        return new QuoteResponse([], []);
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $this->consumeError('createShipment');
        $override = $this->consumeResponse('createShipment');
        if ($override instanceof ShipmentResponse) {
            return $override;
        }
        return new ShipmentResponse(
            carrierTrackingId: 'FAKE-' . bin2hex(random_bytes(4)),
            labelUrl: null,
            status: 'pending',
            raw: ['fake' => true],
        );
    }

    public function cancelShipment(string $carrierTrackingId, ?string $reason = null): CancelResponse
    {
        $this->consumeError('cancelShipment');
        $override = $this->consumeResponse('cancelShipment');
        if ($override instanceof CancelResponse) {
            return $override;
        }
        return new CancelResponse(success: true, carrierMessage: null);
    }

    public function getShipmentStatus(string $carrierTrackingId): StatusResponse
    {
        $this->consumeError('getShipmentStatus');
        $override = $this->consumeResponse('getShipmentStatus');
        if ($override instanceof StatusResponse) {
            return $override;
        }
        return new StatusResponse(
            normalizedStatus: 'pending',
            carrierStatusRaw: 'PENDING',
            occurredAt: null,
            codCollectedAt: null,
        );
    }

    public function fetchLabel(string $carrierTrackingId): LabelResponse
    {
        $this->consumeError('fetchLabel');
        $override = $this->consumeResponse('fetchLabel');
        if ($override instanceof LabelResponse) {
            return $override;
        }
        return new LabelResponse(
            pdfBytes: '%PDF-1.4 fake',
            contentType: 'application/pdf',
            filename: 'label-' . $carrierTrackingId . '.pdf',
        );
    }

    /**
     * @return list<\Shubo\ShippingCore\Api\Data\GeoCacheInterface>
     */
    public function listCities(): array
    {
        $this->consumeError('listCities');
        return [];
    }

    /**
     * @return list<\Shubo\ShippingCore\Api\Data\GeoCacheInterface>
     */
    public function listPudos(?string $cityCode = null): array
    {
        $this->consumeError('listPudos');
        return [];
    }

    private function consumeError(string $op): void
    {
        if (isset($this->nextError[$op])) {
            $error = $this->nextError[$op];
            unset($this->nextError[$op]);
            throw $error;
        }
    }

    private function consumeResponse(string $op): ?object
    {
        if (isset($this->nextResponse[$op])) {
            $response = $this->nextResponse[$op];
            unset($this->nextResponse[$op]);
            return $response;
        }
        return null;
    }
}
