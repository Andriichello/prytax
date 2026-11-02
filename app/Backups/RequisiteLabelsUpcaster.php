<?php

namespace PryTax\Backups;

use PryTax\DataLifecycle\Contracts\ArchiveUpcaster;

/**
 * Class RequisiteLabelsUpcaster.
 *
 * Reads an archive of format 2 into format 3 (backups.md D11), as the migration moved the data on 2026-11-02: a set's
 * BIC becomes its code, labelled «BIC», or, beside a SWIFT, a field of its own named «BIC» (its place in the order
 * kept); the sets get their `labels`, and lose `bic`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class RequisiteLabelsUpcaster implements ArchiveUpcaster
{
    /**
     * Get the format version, which the upcaster reads.
     *
     * @return int
     */
    public function from(): int
    {
        return 2;
    }

    /**
     * Merge each set's BIC into its code, and give the sets their labels.
     *
     * @param array<string, array<int, array<string, string|null>>> $data
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    public function upcast(array $data): array
    {
        foreach ($data['account_requisite_sets'] ?? [] as $index => $set) {
            $bic = $set['bic'] ?? null;
            unset($set['bic']);
            $set['labels'] = null;

            if ($bic !== null && $bic !== '') {
                $order = json_decode((string) ($set['invoice_requisites'] ?? ''), true);

                if (($set['swift'] ?? null) === null || $set['swift'] === '') {
                    $set['swift'] = $bic;
                    $set['labels'] = json_encode(['swift' => 'BIC']);
                    $entry = ['field' => 'swift'];
                } else {
                    $details = json_decode((string) ($set['details'] ?? ''), true) ?: [];
                    $set['details'] = json_encode([...$details, ['label' => 'BIC', 'value' => $bic]], JSON_UNESCAPED_UNICODE);
                    $entry = ['label' => 'BIC'];
                }

                if (is_array($order)) {
                    $set['invoice_requisites'] = json_encode(array_map(
                        fn (array $item) => ($item['field'] ?? null) === 'bic' ? [...$entry, 'shown' => $item['shown'] ?? true] : $item,
                        $order,
                    ), JSON_UNESCAPED_UNICODE);
                }
            }

            $data['account_requisite_sets'][$index] = $set;
        }

        return $data;
    }
}
