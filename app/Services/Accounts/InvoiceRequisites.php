<?php

namespace PryTax\Services\Accounts;

/**
 * Class InvoiceRequisites.
 *
 * The requisites of an account in an invoice's payment details (invoice.md §4), in the order its owner put them, each
 * printed or not: its fields (beneficiary, bank, IBAN, account number, SWIFT), which have a value, and its own details.
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
     * The account's fields, which are requisites, in the built-in order.
     *
     * @var list<string>
     */
    public const array FIELDS = ['holder_name', 'bank_name', 'iban', 'account_number', 'swift'];

    /**
     * Get the account's requisites in order, whether each prints or not.
     *
     * @param array{holder_name?: string|null, bank_name?: string|null, iban?: string|null, account_number?: string|null,
     *     swift?: string|null, details?: list<array{label?: string, value?: string}>|null,
     *     invoice_requisites?: list<array{field?: string|null, label?: string|null, shown?: bool}>|null} $account
     *     The account's attributes, or a snapshot's `account`.
     *
     * @return list<array{field: string|null, label: string|null, value: string, shown: bool}>
     */
    public static function ordered(array $account): array
    {
        $unnamed = [];

        foreach (self::FIELDS as $field) {
            if (filled($account[$field] ?? null)) {
                $unnamed[] = ['field' => $field, 'label' => null, 'value' => (string) $account[$field], 'shown' => true];
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
