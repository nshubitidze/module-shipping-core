<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Console\Command;

use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento shubo:shipping:dlq:list` — list pending DLQ entries.
 *
 * Defaults to pending entries only; pass --source to filter by source;
 * pass --all to include already-reprocessed entries.
 */
class DlqListCommand extends Command
{
    private const OPTION_SOURCE = 'source';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_ALL = 'all';
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly DeadLetterRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shubo:shipping:dlq:list');
        $this->setDescription(
            (string)__('List entries in the shipping dead-letter queue.'),
        );
        $this->addOption(
            self::OPTION_SOURCE,
            null,
            InputOption::VALUE_REQUIRED,
            (string)__('Filter by source: dispatch | webhook | poll | reconcile'),
        );
        $this->addOption(
            self::OPTION_LIMIT,
            null,
            InputOption::VALUE_REQUIRED,
            (string)__('Maximum number of entries to show'),
            (string)self::DEFAULT_LIMIT,
        );
        $this->addOption(
            self::OPTION_ALL,
            null,
            InputOption::VALUE_NONE,
            (string)__('Include already-reprocessed entries (only affects --source listing).'),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int)$input->getOption(self::OPTION_LIMIT));
        $source = $input->getOption(self::OPTION_SOURCE);
        $includeReprocessed = (bool)$input->getOption(self::OPTION_ALL);

        if (is_string($source) && $source !== '') {
            $entries = $this->repository->listBySource($source, $limit, $includeReprocessed);
        } else {
            $entries = $this->repository->listPending($limit);
        }

        if ($entries === []) {
            $output->writeln('<info>' . (string)__('No DLQ entries match the filter.') . '</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Source', 'Carrier', 'Shipment', 'Error', 'Failed at', 'Reprocessed']);
        foreach ($entries as $entry) {
            $table->addRow([
                (string)$entry->getDlqId(),
                $entry->getSource(),
                (string)($entry->getCarrierCode() ?? '-'),
                (string)($entry->getShipmentId() ?? '-'),
                $this->truncate($entry->getErrorMessage(), 60),
                (string)($entry->getFailedAt() ?? '-'),
                (string)($entry->getReprocessedAt() ?? '-'),
            ]);
        }
        $table->render();

        $output->writeln((string)__('Showing %1 entry(ies).', count($entries)));
        return Command::SUCCESS;
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max - 1) . '…';
    }
}
