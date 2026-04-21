<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Fake;

use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;

/**
 * In-memory implementation of {@see DeadLetterEntryInterface} for tests that
 * need a mutable data holder without the Magento AbstractModel machinery.
 *
 * Fixes BUG-SHIPPINGCORE-DLQ-TEST-1: the DLQ publisher/repository tests used
 * `createMock(DeadLetterEntryInterfaceFactory::class)` and
 * `createMock(DeadLetterEntryCollectionFactory::class)`, which fail inside
 * the duka Docker container because those factory classes are only present
 * when Magento's di:compile generator has produced them — and the DLQ ones
 * were never generated (added in Phase 10 after the last compile run).
 *
 * Pattern mirrors {@see InMemoryShipmentEvent} and the BUG-TOOLING-1 fix.
 * Tests that need to assert on the entry's state after the publisher runs
 * should pass this class to {@see InMemoryDeadLetterEntryFactory} and then
 * read the public getters directly.
 */
class InMemoryDeadLetterEntry implements DeadLetterEntryInterface
{
    private ?int $dlqId = null;
    private string $source = '';
    private ?string $carrierCode = null;
    private ?int $shipmentId = null;
    /** @var array<string, mixed> */
    private array $payload = [];
    private string $errorClass = '';
    private string $errorMessage = '';
    private ?string $failedAt = null;
    private ?string $reprocessedAt = null;
    private int $reprocessAttempts = 0;

    public function getDlqId(): ?int
    {
        return $this->dlqId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getCarrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function setCarrierCode(?string $carrierCode): self
    {
        $this->carrierCode = $carrierCode;
        return $this;
    }

    public function getShipmentId(): ?int
    {
        return $this->shipmentId;
    }

    public function setShipmentId(?int $shipmentId): self
    {
        $this->shipmentId = $shipmentId;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    public function getErrorClass(): string
    {
        return $this->errorClass;
    }

    public function setErrorClass(string $errorClass): self
    {
        $this->errorClass = $errorClass;
        return $this;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getFailedAt(): ?string
    {
        return $this->failedAt;
    }

    public function getReprocessedAt(): ?string
    {
        return $this->reprocessedAt;
    }

    public function setReprocessedAt(?string $timestamp): self
    {
        $this->reprocessedAt = $timestamp;
        return $this;
    }

    public function getReprocessAttempts(): int
    {
        return $this->reprocessAttempts;
    }

    public function setReprocessAttempts(int $attempts): self
    {
        $this->reprocessAttempts = $attempts;
        return $this;
    }
}
