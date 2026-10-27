<?php

namespace PryTax\Backups;

use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Registry\TableSpec;
use PryTax\Models\Profile;

/**
 * Class PryTaxTableRegistry.
 *
 * The tables of a ФОП (backups.md §4.2), in FK-safe insert order: the profile, its records, the api-core tables,
 * which its records own, and the reference rows they point to, last. Tests derive the foreign keys, the generated
 * and encrypted columns and the morph pairs from the schema and the models, and fail on anything this list misses;
 * the column snapshot (`tests/Backup/registry.snapshot.json`) makes every new column reviewed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class PryTaxTableRegistry implements TableRegistry
{
    /**
     * Version of the archives' format: bumped for breaking changes of the registry only (D11).
     *
     * @var int
     */
    public const int FORMAT_VERSION = 1;

    /**
     * Tables of PryTax, which aren't backed up, with the reason.
     *
     * @var array<string, string>
     */
    public const array EXCLUDED = [
        'alert_reads' => 'per-user read state',
        'idempotency_keys' => 'transient, 24 h',
    ];

    /**
     * Get the tables in FK-safe insert order.
     *
     * @return array<int, TableSpec>
     */
    public function tables(): array
    {
        return [
            TableSpec::root('profiles')->file('profile')
                ->decrypt('tax_id')
                ->omit('tax_id_hash')
                // An HMAC with the app's key, so it is computed again where the archive is imported
                ->onImport(fn (array $row) => [...$row, 'tax_id_hash' => Profile::hashTaxId((string) $row['tax_id'])]),
            TableSpec::profile('accounts')->decrypt(['iban', 'account_number']),
            TableSpec::profile('account_opening_balances'),
            TableSpec::profile('account_movements'),
            TableSpec::profile('clients'),
            TableSpec::profile('projects'),
            // Before contract_rates and invoice_items, which reference it
            TableSpec::profile('charge_types'),
            TableSpec::profile('contracts'),
            TableSpec::child('contract_rates', parent: 'contracts', fk: 'contract_id'),
            TableSpec::profile('invoices')
                ->decrypt('snapshot', json: true)
                ->rewrite('snapshot', to: 'files', json: '$.profile.signature_file_id')
                ->rewrite('sequence_scope_key', to: 'clients', pattern: '/client:(\d+)/')
                // → files, which point back at the invoice through fileable_*
                ->deferred('primary_file_id')
                ->generated('active_number'),
            TableSpec::child('invoice_items', parent: 'invoices', fk: 'invoice_id'),
            // Before payments (source_transfer_id)
            TableSpec::profile('account_transfers'),
            TableSpec::profile('payments')->generated('period_year', 'period_quarter', 'period_month'),
            TableSpec::profile('refunds')->generated('period_year', 'period_quarter', 'period_month'),
            TableSpec::child('transfer_allocations', parent: 'account_transfers', fk: 'transfer_id'),
            TableSpec::profile('tax_periods'),
            TableSpec::profile('esv_obligations'),
            TableSpec::profile('tax_payments')->generated('receipt_tax_key'),
            TableSpec::profile('tax_opening_balances'),
            TableSpec::profile('tax_payment_requisites'),
            // Derived: AlertSync rebuilds them after an import
            TableSpec::profile('alerts')->exportOnly(),

            ...ApiCoreTables::owned(),

            // Global rows, which the rows above reference: matched on import, never inserted
            TableSpec::reference('tax_rules', via: ['payments.tax_rule_id', 'refunds.tax_rule_id', 'esv_obligations.tax_rule_id'])
                ->matchOn('effective_from'),
            TableSpec::reference('exchange_rates', via: ['payments.exchange_rate_id', 'refunds.exchange_rate_id'])
                ->matchOn(['currency', 'rate_date']),
            ...ApiCoreTables::references(),
        ];
    }

    /**
     * Get the tables, which are left out on purpose, with the reason.
     *
     * @return array<string, string>
     */
    public function excluded(): array
    {
        return [...self::EXCLUDED, ...ApiCoreTables::EXCLUDED];
    }

    /**
     * Get the version of the archives' format.
     *
     * @return int
     */
    public function formatVersion(): int
    {
        return self::FORMAT_VERSION;
    }
}
