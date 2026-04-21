<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Console\Command;

use Shubo\ShippingCore\Api\TrackingPollerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento shubo:shipping:poll` — one-shot trigger for the tracking poller.
 *
 * Intended for ops: runs the same drainBatch that the cron job calls, so an
 * operator can force a refresh without waiting for the schedule. Exits 0 when
 * the drain completes (even if zero shipments were eligible); exits 1 only on
 * unhandled exception.
 */
class PollCommand extends Command
{
    private const OPTION_LIMIT = 'limit';
    private const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly TrackingPollerInterface $poller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shubo:shipping:poll');
        $this->setDescription((string)__('Drain the shipping poll queue across all enabled carriers.'));
        $this->addOption(
            self::OPTION_LIMIT,
            null,
            InputOption::VALUE_REQUIRED,
            (string)__('Max shipments to poll in this batch'),
            (string)self::DEFAULT_LIMIT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int)$input->getOption(self::OPTION_LIMIT));

        try {
            $polled = $this->poller->drainBatch($limit);
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string)__('Poll drain failed: %1', $e->getMessage()),
            ));
            return Command::FAILURE;
        }

        $output->writeln((string)__('Polled %1 shipment(s).', $polled));
        return Command::SUCCESS;
    }
}
