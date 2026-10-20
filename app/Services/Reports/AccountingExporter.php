<?php

namespace PryTax\Services\Reports;

use PryTax\Models\Profile;

/**
 * Class AccountingExporter.
 *
 * CSV exports of the accounting (design.md §7): the rows of a quarter, and the income book of a year (п. 296.1),
 * with a UTF-8 byte order mark, so spreadsheets read the Cyrillic. Amounts are canonical (`1234.99`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class AccountingExporter
{
    /**
     * AccountingExporter constructor.
     *
     * @param AccountingView $accounting
     * @param IncomeBook $incomeBook
     */
    public function __construct(
        protected AccountingView $accounting,
        protected IncomeBook $incomeBook
    ) {}

    /**
     * Get the CSV of the quarter's receipts and refunds.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return string
     */
    public function quarter(Profile $profile, int $year, int $quarter): string
    {
        $rows = [[
            'Дата', 'Документ', 'Клієнт / опис', 'Валюта', 'Сума', 'Курс НБУ', 'Дохід, ₴', 'ЄП', 'ВЗ', 'Рахунок',
        ]];

        foreach ($this->accounting->rows($year, $quarter) as $row) {
            $rows[] = [
                $row['date'],
                $row['invoice_number'] ?? ($row['type'] === 'refund' ? 'Повернення' : ''),
                $row['client_name'] ?? $row['description'] ?? '',
                $row['currency'],
                $row['amount'],
                $row['nbu_rate'],
                $row['uah_amount'],
                $row['single_tax_uah'] ?? '',
                $row['military_levy_uah'] ?? '',
                $row['account_name'] ?? '',
            ];
        }

        return $this->csv($rows);
    }

    /**
     * Get the CSV of the year's income book.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return string
     */
    public function incomeBook(Profile $profile, int $year): string
    {
        $book = $this->incomeBook->forYear($profile, $year);
        $rows = [['Місяць', 'Дохід, ₴', 'Наростаючим, ₴']];

        foreach ($book['months'] as $month) {
            $rows[] = [sprintf('%d-%02d', $year, $month['month']), $month['income'], $month['cumulative']];

            if ($month['month'] % 3 === 0) {
                $quarter = $book['quarters'][$month['quarter'] - 1];
                $rows[] = [['I', 'II', 'III', 'IV'][$month['quarter'] - 1] . ' кв.', $quarter['income'], $quarter['cumulative']];
            }
        }

        $rows[] = ['Рік', $book['total'], $book['total']];

        return $this->csv($rows);
    }

    /**
     * Encode the rows as CSV with a byte order mark.
     *
     * @param array<int, array<int, string|null>> $rows
     *
     * @return string
     */
    protected function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . $csv;
    }
}
