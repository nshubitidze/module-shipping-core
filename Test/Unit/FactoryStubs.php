<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * Minimal factory stubs loaded before PHPUnit runs.
 *
 * Magento generates these factories at runtime from
 * `xsi:type="object"` arguments in di.xml. In a standalone unit-test
 * environment the code generator is not active, so PHPUnit's reflection
 * mock builder cannot synthesize mocks against class names that resolve
 * to nothing. Declaring paper-thin stubs here keeps the tests fast while
 * still allowing the SUT to depend on the real class names.
 *
 * The stubs are namespaced via `namespace ... { }` bracket syntax so a
 * single file can declare multiple namespaces without polluting the
 * global root namespace.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Files.SideEffects
// phpcs:disable Squiz.Classes.ClassFileName
// phpcs:disable Magento2.NamingConvention.ReservedWords.ForbiddenAsNameSpace
namespace Magento\Quote\Model\Quote\Address\RateResult {
    if (!\class_exists(ErrorFactory::class, false)) {
        class ErrorFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
    if (!\class_exists(MethodFactory::class, false)) {
        class MethodFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
}

namespace Magento\Shipping\Model\Rate {
    if (!\class_exists(ResultFactory::class, false)) {
        class ResultFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
}

namespace Shubo\ShippingCore\Model\ResourceModel\Shipment {
    if (!\class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
}

namespace Shubo\ShippingCore\Api\Data {
    if (!\class_exists(ShipmentEventInterfaceFactory::class, false)) {
        /**
         * Stub for the Magento-generated factory. Returns a fresh in-memory
         * implementation of {@see ShipmentEventInterface} — tests that depend
         * on the factory can then call the `set*` fluent API to build an event
         * without touching the real resource model.
         */
        class ShipmentEventInterfaceFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): ShipmentEventInterface
            {
                return new class implements ShipmentEventInterface {
                    private ?int $eventId = null;
                    private int $shipmentId = 0;
                    private string $carrierCode = '';
                    private string $eventType = '';
                    private ?string $carrierStatusRaw = null;
                    private ?string $normalizedStatus = null;
                    private ?string $occurredAt = null;
                    private ?string $receivedAt = null;
                    private string $source = '';
                    private ?string $externalEventId = null;
                    /** @var array<string, mixed> */
                    private array $rawPayload = [];

                    public function getEventId(): ?int
                    {
                        return $this->eventId;
                    }

                    public function getShipmentId(): int
                    {
                        return $this->shipmentId;
                    }

                    public function setShipmentId(int $shipmentId): self
                    {
                        $this->shipmentId = $shipmentId;
                        return $this;
                    }

                    public function getCarrierCode(): string
                    {
                        return $this->carrierCode;
                    }

                    public function setCarrierCode(string $carrierCode): self
                    {
                        $this->carrierCode = $carrierCode;
                        return $this;
                    }

                    public function getEventType(): string
                    {
                        return $this->eventType;
                    }

                    public function setEventType(string $eventType): self
                    {
                        $this->eventType = $eventType;
                        return $this;
                    }

                    public function getCarrierStatusRaw(): ?string
                    {
                        return $this->carrierStatusRaw;
                    }

                    public function setCarrierStatusRaw(?string $status): self
                    {
                        $this->carrierStatusRaw = $status;
                        return $this;
                    }

                    public function getNormalizedStatus(): ?string
                    {
                        return $this->normalizedStatus;
                    }

                    public function setNormalizedStatus(?string $status): self
                    {
                        $this->normalizedStatus = $status;
                        return $this;
                    }

                    public function getOccurredAt(): ?string
                    {
                        return $this->occurredAt;
                    }

                    public function setOccurredAt(?string $timestamp): self
                    {
                        $this->occurredAt = $timestamp;
                        return $this;
                    }

                    public function getReceivedAt(): ?string
                    {
                        return $this->receivedAt;
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

                    public function getExternalEventId(): ?string
                    {
                        return $this->externalEventId;
                    }

                    public function setExternalEventId(?string $externalEventId): self
                    {
                        $this->externalEventId = $externalEventId;
                        return $this;
                    }

                    public function getRawPayload(): array
                    {
                        return $this->rawPayload;
                    }

                    public function setRawPayload(array $payload): self
                    {
                        $this->rawPayload = $payload;
                        return $this;
                    }
                };
            }
        }
    }
}

// phpcs:enable
