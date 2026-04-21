<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento shubo:shipping:schema-drift` — detect drift between the
 * Shubo_ShippingCore declarative schema and the live DB.
 *
 * Compares:
 *  - Expected tables declared in `etc/db_schema.xml` vs. tables that exist.
 *  - Expected columns of each table vs. actual columns.
 *
 * Exits 0 when the schema is in sync, 1 when drift is detected. Intended as
 * the last check in the go-live checklist.
 */
class SchemaDriftCommand extends Command
{
    /**
     * Map of expected tables to their declared columns (order-insensitive).
     *
     * Kept in sync manually with etc/db_schema.xml — the module ships with
     * a generated whitelist at etc/db_schema_whitelist.json, but that file
     * is allowed to include extra columns from patches so we can't use it
     * directly. Hand-maintained mapping is the source of truth for drift
     * detection.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED = [
        'shubo_shipping_shipment' => [
            'shipment_id', 'magento_shipment_id', 'order_id', 'merchant_id',
            'carrier_code', 'carrier_tracking_id', 'client_tracking_code',
            'status', 'pickup_address_id', 'delivery_address_json',
            'parcel_weight_grams', 'parcel_value_cents', 'cod_enabled',
            'cod_amount_cents', 'cod_collected_at', 'cod_reconciled_at',
            'label_url', 'label_pdf_stored_at', 'created_at', 'updated_at',
            'last_polled_at', 'next_poll_at', 'poll_strategy', 'webhook_secret',
            'failed_at', 'failure_reason', 'metadata_json',
        ],
        'shubo_shipping_shipment_event' => [
            'event_id', 'shipment_id', 'carrier_code', 'event_type',
            'carrier_status_raw', 'normalized_status', 'occurred_at',
            'received_at', 'source', 'external_event_id', 'raw_payload_json',
        ],
        'shubo_shipping_carrier_config' => [
            'carrier_code', 'is_enabled', 'is_sandbox', 'priority',
            'credentials_encrypted', 'capabilities_cache_json', 'rate_limit_rpm',
            'timeout_seconds', 'updated_at',
        ],
        'shubo_shipping_geo_cache' => [
            'geo_id', 'carrier_code', 'geo_type', 'external_id', 'name',
            'name_en', 'parent_id', 'latitude', 'longitude', 'metadata_json',
            'refreshed_at',
        ],
        'shubo_shipping_invoice_import' => [
            'import_id', 'carrier_code', 'period_start', 'period_end',
            'source_file_hash', 'source_file_path', 'source_format',
            'total_lines', 'matched_lines', 'unmatched_lines', 'disputed_lines',
            'status', 'imported_at', 'reconciled_at', 'imported_by_admin_id',
            'error_message',
        ],
        'shubo_shipping_invoice_line' => [
            'line_id', 'import_id', 'carrier_tracking_id', 'external_line_id',
            'shipment_id', 'expected_cod_cents', 'reported_cod_cents',
            'reported_fee_cents', 'reported_vat_cents', 'match_status',
            'dispute_reason', 'matched_at', 'raw_line_json',
        ],
        'shubo_shipping_circuit_breaker' => [
            'carrier_code', 'state', 'failure_count', 'success_count_since_halfopen',
            'last_failure_at', 'last_success_at', 'opened_at', 'cooldown_until',
            'updated_at',
        ],
        'shubo_shipping_rate_limit' => [
            'carrier_code', 'window_start', 'tokens_used', 'updated_at',
        ],
        'shubo_shipping_dead_letter' => [
            'dlq_id', 'source', 'carrier_code', 'shipment_id', 'payload_json',
            'error_class', 'error_message', 'failed_at', 'reprocessed_at',
            'reprocess_attempts',
        ],
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shubo:shipping:schema-drift');
        $this->setDescription(
            (string)__('Detect drift between Shubo_ShippingCore schema and the live DB.'),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $driftFound = false;

        foreach (self::EXPECTED as $tableName => $expectedColumns) {
            $realTable = $this->resource->getTableName($tableName);
            if (!$connection->isTableExists($realTable)) {
                $output->writeln(sprintf(
                    '<error>%s</error>',
                    (string)__('MISSING TABLE: %1', $tableName),
                ));
                $driftFound = true;
                continue;
            }

            $actualColumns = array_keys($connection->describeTable($realTable));
            $missing = array_values(array_diff($expectedColumns, $actualColumns));
            $extra = array_values(array_diff($actualColumns, $expectedColumns));

            if ($missing !== []) {
                $output->writeln(sprintf(
                    '<error>%s</error>',
                    (string)__(
                        '%1: MISSING columns: %2',
                        $tableName,
                        implode(', ', $missing),
                    ),
                ));
                $driftFound = true;
            }

            if ($extra !== []) {
                $output->writeln(sprintf(
                    '<comment>%s</comment>',
                    (string)__(
                        '%1: EXTRA columns (added by patch / manual DDL): %2',
                        $tableName,
                        implode(', ', $extra),
                    ),
                ));
                // Extras are warnings, not errors — patches may add columns.
            }

            if ($missing === [] && $extra === []) {
                $output->writeln(sprintf(
                    '<info>%s</info>',
                    (string)__('%1: in sync (%2 columns)', $tableName, count($expectedColumns)),
                ));
            }
        }

        if ($driftFound) {
            $output->writeln('');
            $output->writeln('<error>' . (string)__('Schema drift detected. Run bin/magento setup:upgrade.') . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>' . (string)__('No drift detected across %1 tables.', count(self::EXPECTED)) . '</info>');
        return Command::SUCCESS;
    }
}
