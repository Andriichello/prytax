<?php

namespace PryTax\Backups;

use PryTax\DataLifecycle\Contracts\ArchiveUpcaster;

/**
 * Class RequisiteSetsUpcaster.
 *
 * Reads an archive of format 1 into format 2 (backups.md D11), as the migration moved the data on 2026-11-02: each
 * account with a requisite gets them as its default set (a row of `account_requisite_sets`, its id the account's,
 * no BIC: format 1 had a SWIFT only), and each invoice paid into it, but a record without an invoice, prints that set
 * (a row of `invoice_payment_sections`, its id the invoice's). The account's rows lose the requisites' columns. The
 * importer maps every id anew, so the borrowed ids only tie the rows of the archive together.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class RequisiteSetsUpcaster implements ArchiveUpcaster
{
    /**
     * The account's columns of format 1, which a set of requisites took over.
     *
     * @var list<string>
     */
    private const array COLUMNS = ['holder_name', 'bank_name', 'iban', 'swift', 'account_number', 'details', 'invoice_requisites'];

    /**
     * Get the format version, which the upcaster reads.
     *
     * @return int
     */
    public function from(): int
    {
        return 1;
    }

    /**
     * Move the accounts' requisites into their default sets, and give each invoice paid into one that set.
     *
     * @param array<string, array<int, array<string, string|null>>> $data
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    public function upcast(array $data): array
    {
        $sets = [];

        foreach ($data['accounts'] ?? [] as $index => $account) {
            $filled = array_filter(self::COLUMNS, fn (string $column) => !in_array($account[$column] ?? null, [null, '', '[]', 'null'], true));

            if ($filled !== []) {
                $sets[(string) $account['id']] = [
                    'id' => $account['id'],
                    'profile_id' => $account['profile_id'] ?? null,
                    'account_id' => $account['id'],
                    'created_by_user_id' => $account['created_by_user_id'] ?? null,
                    'name' => null,
                    ...array_combine(self::COLUMNS, array_map(fn (string $column) => $account[$column] ?? null, self::COLUMNS)),
                    'bic' => null,
                    'is_default' => '1',
                    'is_active' => '1',
                    'sort_order' => '0',
                    'created_at' => $account['created_at'] ?? null,
                    'updated_at' => $account['updated_at'] ?? null,
                ];
            }

            $data['accounts'][$index] = array_diff_key($account, array_flip(self::COLUMNS));
        }

        $sections = [];

        foreach ($data['invoices'] ?? [] as $invoice) {
            $set = $sets[(string) ($invoice['receiving_account_id'] ?? '')] ?? null;

            if ($set !== null && ($invoice['source'] ?? null) !== 'virtual') {
                $sections[] = [
                    'id' => $invoice['id'],
                    'invoice_id' => $invoice['id'],
                    'requisite_set_id' => $set['id'],
                    'position' => '1',
                    'title' => null,
                    'show_title' => '0',
                    'created_at' => $invoice['created_at'] ?? null,
                    'updated_at' => $invoice['updated_at'] ?? null,
                ];
            }
        }

        $data['account_requisite_sets'] = array_values($sets);
        $data['invoice_payment_sections'] = $sections;

        return $data;
    }
}
