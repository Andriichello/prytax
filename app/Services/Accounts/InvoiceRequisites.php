<?php

namespace PryTax\Services\Accounts;

/**
 * Class InvoiceRequisites.
 *
 * The requisites of a set of an account's (`AccountRequisiteSet`) in an invoice's payment details (invoice.md §4), in
 * the order its owner put them, each printed or not: its fields (beneficiary, bank, IBAN, account number, SWIFT /
 * BIC), which have a value, each under the label its owner gave it (`labels`) or none, and its own details.
 * `invoice_requisites` names them in order, a field by its column and a detail by its label, each with `shown`; a
 * requisite it doesn't name (added later, or renamed) follows the named ones, in the built-in order, and prints.
 * Without it, every requisite prints in the built-in order: the fields, then the details as they were typed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Accounts
 */
final class InvoiceRequisites
{
    /**
     * The fields of a set, which are requisites, in the built-in order.
     *
     * @var list<string>
     */
    public const array FIELDS = ['holder_name', 'bank_name', 'iban', 'account_number', 'swift'];

    /**
     * Fields, which only snapshots frozen while they were apart hold: a BIC beside the SWIFT (schema 4).
     *
     * @var list<string>
     */
    public const array FROZEN_FIELDS = ['bic'];

    /**
     * Get the account's requisites in order, whether each prints or not.
     *
     * @param array{holder_name?: string|null, bank_name?: string|null, iban?: string|null, account_number?: string|null,
     *     swift?: string|null, bic?: string|null, details?: list<array{label?: string, value?: string}>|null,
     *     labels?: array<string, string>|null,
     *     invoice_requisites?: list<array{field?: string|null, label?: string|null, shown?: bool}>|null} $account
     *     The set's requisites, a section of a snapshot, or a snapshot's `account` before schema 4.
     *
     * @return list<array{field: string|null, label: string|null, value: string, shown: bool}> A field's label is its
     *                                                                                         owner's, or null for the invoice's language's.
     */
    public static function ordered(array $account): array
    {
        $unnamed = [];

        foreach ([...self::FIELDS, ...self::FROZEN_FIELDS] as $field) {
            if (filled($account[$field] ?? null)) {
                $label = trim((string) ($account['labels'][$field] ?? ''));
                $unnamed[] = ['field' => $field, 'label' => $label === '' ? null : $label, 'value' => (string) $account[$field], 'shown' => true];
            }
        }

        foreach ($account['details'] ?? [] as $detail) {
            $unnamed[] = ['field' => null, 'label' => (string) ($detail['label'] ?? ''), 'value' => (string) ($detail['value'] ?? ''), 'shown' => true];
        }

        $named = [];

        foreach ($account['invoice_requisites'] ?? [] as $entry) {
            $index = self::find($unnamed, (array) $entry);

            if ($index !== null) {
                $named[] = [...$unnamed[$index], 'shown' => (bool) ($entry['shown'] ?? true)];
                unset($unnamed[$index]);
            }
        }

        return [...$named, ...array_values($unnamed)];
    }

    /**
     * Find the requisite, which an entry of the order names: a field by its column, a detail by its label (the first
     * of two with one label, which isn't named yet).
     *
     * @param array<int, array{field: string|null, label: string|null, value: string, shown: bool}> $requisites
     * @param array{field?: string|null, label?: string|null} $entry
     *
     * @return int|null
     */
    private static function find(array $requisites, array $entry): ?int
    {
        foreach ($requisites as $index => $requisite) {
            $names = filled($entry['field'] ?? null)
                ? $requisite['field'] === $entry['field']
                : $requisite['field'] === null && $requisite['label'] === (string) ($entry['label'] ?? '');

            if ($names) {
                return $index;
            }
        }

        return null;
    }
}
