<?php

namespace PryTax\Services\Reports;

use PryTax\Enums\MoneyFormat;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;

/**
 * Class AccountingExporter.
 *
 * CSV exports of the accounting (design.md §7, suggestions §3.15, §3.19): the rows of a quarter with their fees, net
 * income and the quarter's summary; the year's receipts and taxes ("Усі надходження та податки"), quarter by quarter
 * with the tax payments; and the income book of a year (п. 296.1), each document with its monthly and cumulative
 * totals and the quarters' subtotals, so an inspector can read it. With a UTF-8 byte order mark, so spreadsheets read
 * the Cyrillic. Amounts have no grouping and the profile's decimal separator; with a comma, columns are separated by
 * a semicolon, as spreadsheets in Ukrainian expect.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class AccountingExporter
{
    /**
     * Names of the taxes in the exports.
     *
     * @var array<string, string>
     */
    public const array TAXES = ['single_tax' => 'ЄП', 'military_levy' => 'ВЗ', 'social_contribution' => 'ЄСВ'];

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
     * Get the CSV of the quarter's receipts and refunds, with the quarter's summary.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return string
     */
    public function quarter(Profile $profile, int $year, int $quarter): string
    {
        return $this->csv($profile, [
            $this->rowsHeader(),
            ...$this->rows($year, $quarter),
            [],
            ...$this->summary($this->accounting->forQuarter($profile, $year, $quarter)['summary']),
        ]);
    }

    /**
     * Get the CSV of the year's receipts and taxes: each quarter's rows with its summary, then the tax payments.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return string
     */
    public function year(Profile $profile, int $year): string
    {
        $rows = [$this->rowsHeader()];

        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $rows[] = [['I', 'II', 'III', 'IV'][$quarter - 1] . " кв. $year"];
            $rows = [...$rows, ...$this->rows($year, $quarter), ...$this->summary($this->accounting->forQuarter($profile, $year, $quarter)['summary']), []];
        }

        $rows[] = ['Сплата податків'];
        $rows[] = ['Дата', 'Податок', 'Період', 'Сума, ₴', 'Статус', 'Рахунок', 'Призначення'];

        TaxPayment::query()
            ->where('period_year', $year)
            ->with(['paidFromAccount', 'payment.invoice'])
            ->orderBy('paid_at')
            ->get()
            ->each(function (TaxPayment $payment) use (&$rows) {
                $rows[] = [
                    $payment->paid_at?->toDateString() ?? '',
                    self::TAXES[$payment->tax_type->value],
                    match ($payment->allocation->value) {
                        'payment' => 'надходження ' . ($payment->payment?->invoice?->number ?? $payment->payment?->received_at?->toDateString() ?? ''),
                        'month' => sprintf('%d-%02d', $payment->period_year, $payment->period_month),
                        default => ['I', 'II', 'III', 'IV'][$payment->period_quarter - 1] . " кв. {$payment->period_year}",
                    },
                    $payment->amount,
                    $payment->status->value,
                    $payment->paidFromAccount?->name ?? '',
                    $payment->reference ?? '',
                ];
            });

        return $this->csv($profile, $rows);
    }

    /**
     * Get the CSV of the year's income book: each document of income, the totals of its month and the cumulative one,
     * and the quarters' and the year's subtotals.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return string
     */
    public function incomeBook(Profile $profile, int $year): string
    {
        $book = $this->incomeBook->forYear($profile, $year);
        $rows = [['Дата', 'Документ', 'Клієнт / опис', 'Валюта', 'Сума', 'Курс НБУ', 'Дохід, ₴', 'За місяць, ₴', 'Наростаючим, ₴']];
        $documents = collect(range(1, 4))->flatMap(fn (int $quarter) => $this->accounting->rows($year, $quarter))->groupBy('month');

        foreach ($book['months'] as $month) {
            foreach ($documents->get($month['month'], []) as $row) {
                $rows[] = [
                    $row['date'],
                    $row['invoice_number'] ?? ($row['type'] === 'refund' ? 'Повернення' : ''),
                    $row['client_name'] ?? $row['description'] ?? '',
                    $row['currency'],
                    $row['amount'],
                    $row['nbu_rate'],
                    $row['uah_amount'],
                    '',
                    '',
                ];
            }

            $rows[] = [sprintf('%d-%02d', $year, $month['month']), 'Підсумок місяця', '', '', '', '', '', $month['income'], $month['cumulative']];

            if ($month['month'] % 3 === 0) {
                $quarter = $book['quarters'][$month['quarter'] - 1];
                $rows[] = [['I', 'II', 'III', 'IV'][$month['quarter'] - 1] . ' кв.', 'Підсумок кварталу', '', '', '', '', '', $quarter['income'], $quarter['cumulative']];
            }
        }

        $rows[] = ['Рік', 'Підсумок року', '', '', '', '', '', $book['total'], $book['total']];

        return $this->csv($profile, $rows);
    }

    /**
     * Get the header of the rows of receipts.
     *
     * @return array<int, string>
     */
    protected function rowsHeader(): array
    {
        return ['Дата', 'Документ', 'Клієнт / опис', 'Валюта', 'Сума', 'Курс НБУ', 'Дохід, ₴', 'ЄП', 'ВЗ', 'Комісії', 'Чисто', 'Рахунок'];
    }

    /**
     * Get the rows of the quarter's receipts and refunds.
     *
     * @param int $year
     * @param int $quarter
     *
     * @return array<int, array<int, string|null>>
     */
    protected function rows(int $year, int $quarter): array
    {
        return $this->accounting->rows($year, $quarter)->map(fn (array $row) => [
            $row['date'],
            $row['invoice_number'] ?? ($row['type'] === 'refund' ? 'Повернення' : ''),
            $row['client_name'] ?? $row['description'] ?? '',
            $row['currency'],
            $row['amount'],
            $row['nbu_rate'],
            $row['uah_amount'],
            $row['single_tax_uah'] ?? '',
            $row['military_levy_uah'] ?? '',
            $row['fees'] ?? '',
            $row['net'] ?? '',
            $row['account_name'] ?? '',
        ])->all();
    }

    /**
     * Get the rows of a quarter's summary: its income, the year-to-date one, what it owes of ЄП and ВЗ with their
     * cross-checks, its ЄСВ, and the cap.
     *
     * @param array<string, mixed> $summary
     *
     * @return array<int, array<int, string>>
     */
    protected function summary(array $summary): array
    {
        return [
            ['Дохід кварталу, ₴', $summary['quarter_income']],
            ['Дохід з початку року, ₴', $summary['ytd_income']],
            ['ЄП до сплати, ₴', $summary['single_tax']['owed'], 'окремо', $summary['single_tax']['standalone'], 'за надходженнями', $summary['single_tax']['hints_sum']],
            ['ВЗ до сплати, ₴', $summary['military_levy']['owed'], 'окремо', $summary['military_levy']['standalone'], 'за надходженнями', $summary['military_levy']['hints_sum']],
            ['ЄСВ, ₴', $summary['esv']['owed']],
            ['Ліміт доходу, %', $summary['cap']['percent']],
        ];
    }

    /**
     * Encode the rows as CSV with a byte order mark, in the profile's decimal separator.
     *
     * @param Profile $profile
     * @param array<int, array<int, string|null>> $rows
     *
     * @return string
     */
    protected function csv(Profile $profile, array $rows): string
    {
        $comma = MoneyFormat::tryFrom((string) $profile->setting('display.money_format'))?->decimalSeparator() === ',';
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            $cells = array_map(
                fn ($cell) => $comma && is_string($cell) && preg_match('/^-?\d+\.\d+$/', $cell) ? str_replace('.', ',', $cell) : $cell,
                $row
            );

            fputcsv($handle, $cells, $comma ? ';' : ',', escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . $csv;
    }
}
