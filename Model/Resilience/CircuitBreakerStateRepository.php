<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\Exception\CouldNotSaveException;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Model\Data\CircuitBreakerState;
use Shubo\ShippingCore\Model\Data\CircuitBreakerStateFactory;
use Shubo\ShippingCore\Model\ResourceModel\CircuitBreakerState as CircuitBreakerStateResource;

/**
 * Internal repository for circuit-breaker state rows. Not exposed via Api/
 * — callers should go through {@see \Shubo\ShippingCore\Api\CircuitBreakerInterface}.
 *
 * Read semantics: if no row exists for the carrier, returns a freshly
 * constructed (unsaved) instance with state=closed and all counters zero.
 * This lets the breaker state machine treat "first call" exactly like
 * "idle closed" without a conditional.
 */
class CircuitBreakerStateRepository
{
    public function __construct(
        private readonly CircuitBreakerStateFactory $factory,
        private readonly CircuitBreakerStateResource $resource,
    ) {
    }

    /**
     * Load the state row for a carrier. Returns a new (not-yet-saved)
     * instance seeded with state=closed if no row exists.
     */
    public function getByCarrierCode(string $carrierCode): CircuitBreakerStateInterface
    {
        /** @var CircuitBreakerState $state */
        $state = $this->factory->create();
        $this->resource->load($state, $carrierCode, CircuitBreakerStateInterface::FIELD_CARRIER_CODE);
        if ($state->getCarrierCode() === '') {
            $state->setCarrierCode($carrierCode);
            $state->setState(CircuitBreakerStateInterface::STATE_CLOSED);
            $state->setFailureCount(0);
            $state->setSuccessCountSinceHalfopen(0);
        }
        return $state;
    }

    /**
     * Persist a state row. Returns the same instance after save.
     *
     * @throws CouldNotSaveException
     */
    public function save(CircuitBreakerStateInterface $state): CircuitBreakerStateInterface
    {
        if (!$state instanceof CircuitBreakerState) {
            throw new CouldNotSaveException(
                __('CircuitBreakerStateRepository::save requires the Model\\Data implementation.'),
            );
        }
        try {
            $this->resource->save($state);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save circuit breaker state: %1', $e->getMessage()),
                $e,
            );
        }
        return $state;
    }

    /**
     * Return all OPEN state rows whose cooldown window has elapsed.
     *
     * Used by {@see \Shubo\ShippingCore\Cron\ReapCircuitBreakers} to proactively
     * flip expired breakers to HALF_OPEN so the next carrier call becomes the
     * trial probe. Without this cron, a breaker only transitions lazily when a
     * caller invokes {@see \Shubo\ShippingCore\Api\CircuitBreakerInterface::execute()}
     * — which never happens for a fully idle carrier. Reaping keeps the
     * admin dashboard's "open" count accurate.
     *
     * @param string $nowGmt GMT timestamp formatted as `Y-m-d H:i:s`.
     * @return list<CircuitBreakerStateInterface>
     */
    public function findExpiredOpenStates(string $nowGmt): array
    {
        $connection = $this->resource->getConnection();
        if ($connection === false) {
            return [];
        }
        $select = $connection->select()
            ->from($this->resource->getMainTable())
            ->where(CircuitBreakerStateInterface::FIELD_STATE . ' = ?', CircuitBreakerStateInterface::STATE_OPEN)
            ->where(CircuitBreakerStateInterface::FIELD_COOLDOWN_UNTIL . ' <= ?', $nowGmt);
        $rows = $connection->fetchAll($select);

        $result = [];
        foreach ($rows as $row) {
            /** @var CircuitBreakerState $state */
            $state = $this->factory->create();
            $state->setData($row);
            $result[] = $state;
        }
        return $result;
    }
}
