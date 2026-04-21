<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Api\ShipmentOrchestratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento shubo:shipping:dlq:reprocess <id>` — replay a single DLQ entry.
 *
 * Only dispatch-source entries are retriable automatically (via
 * {@see ShipmentOrchestratorInterface::retry()}). Other sources require
 * source-specific handlers which are wired in follow-up phases; this command
 * reports them as unsupported rather than silently marking them reprocessed.
 */
class DlqReprocessCommand extends Command
{
    private const ARG_DLQ_ID = 'dlq_id';

    public function __construct(
        private readonly DeadLetterRepositoryInterface $repository,
        private readonly ShipmentOrchestratorInterface $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shubo:shipping:dlq:reprocess');
        $this->setDescription(
            (string)__('Reprocess a single shipping dead-letter entry by its DLQ id.'),
        );
        $this->addArgument(
            self::ARG_DLQ_ID,
            InputArgument::REQUIRED,
            (string)__('DLQ entry id (numeric)'),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dlqId = (int)$input->getArgument(self::ARG_DLQ_ID);
        if ($dlqId <= 0) {
            $output->writeln('<error>' . (string)__('DLQ id must be a positive integer.') . '</error>');
            return Command::INVALID;
        }

        try {
            $entry = $this->repository->getById($dlqId);
        } catch (NoSuchEntityException $e) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string)__('DLQ entry %1 does not exist.', $dlqId),
            ));
            return Command::FAILURE;
        }

        if ($entry->getReprocessedAt() !== null) {
            $output->writeln(sprintf(
                '<comment>%s</comment>',
                (string)__(
                    'DLQ entry %1 was already reprocessed at %2. Skipping.',
                    $dlqId,
                    $entry->getReprocessedAt(),
                ),
            ));
            return Command::SUCCESS;
        }

        $entry->setReprocessAttempts($entry->getReprocessAttempts() + 1);

        $source = $entry->getSource();
        if ($source !== DeadLetterEntryInterface::SOURCE_DISPATCH) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string)__(
                    'DLQ entry %1 has source "%2" which is not auto-reprocessable. '
                    . 'Retry manually via the admin shipments grid.',
                    $dlqId,
                    $source,
                ),
            ));
            $this->repository->save($entry);
            return Command::FAILURE;
        }

        $shipmentId = $entry->getShipmentId();
        if ($shipmentId === null || $shipmentId <= 0) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string)__(
                    'DLQ entry %1 has no shipment_id; nothing to retry.',
                    $dlqId,
                ),
            ));
            $this->repository->save($entry);
            return Command::FAILURE;
        }

        try {
            $shipment = $this->orchestrator->retry($shipmentId);
        } catch (\Throwable $e) {
            $entry->setErrorMessage(
                'reprocess-failed: ' . $e->getMessage(),
            );
            $this->repository->save($entry);
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string)__(
                    'Reprocess of DLQ entry %1 (shipment %2) failed: %3',
                    $dlqId,
                    $shipmentId,
                    $e->getMessage(),
                ),
            ));
            return Command::FAILURE;
        }

        $entry->setReprocessedAt(gmdate('Y-m-d H:i:s'));
        $this->repository->save($entry);

        $output->writeln(sprintf(
            '<info>%s</info>',
            (string)__(
                'Reprocess of DLQ entry %1 (shipment %2) succeeded. New status: %3',
                $dlqId,
                $shipmentId,
                $shipment->getStatus(),
            ),
        ));
        return Command::SUCCESS;
    }
}
