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
            $rows[] = [$this->quarterLabel($quarter, $year)];
            $rows = [...$rows, ...$this->rows($year, $quarter), ...$this->summary($this->accounting->forQuarter($profile, $year, $quarter)['summary']), []];
        }

        $rows[] = [__('Tax payments')];
        $rows[] = [__('Date'), __('Tax'), __('Period'), __('Amount, ₴'), __('Status'), __('Account'), __('Purpose')];

        TaxPayment::query()
            ->where('period_year', $year)
            ->with(['paidFromAccount', 'payment.invoice'])
            ->orderBy('paid_at')
            ->get()
            ->each(function (TaxPayment $payment) use (&$rows) {
                $rows[] = [
                    $payment->paid_at?->toDateString() ?? '',
                    $this->taxName($payment->tax_type->value),
                    match ($payment->allocation->value) {
                        'payment' => __('receipt :invoice', ['invoice' => $payment->payment?->invoice?->number ?? $payment->payment?->received_at?->toDateString() ?? '']),
                        'month' => sprintf('%d-%02d', $payment->period_year, $payment->period_month),
                        default => $this->quarterLabel($payment->period_quarter, $payment->period_year),
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
        $rows = [[__('Date'), __('Document'), __('Client / description'), __('Currency'), __('Amount'), __('NBU rate'), __('Income, ₴'), __('For the month, ₴'), __('Cumulative, ₴')]];
        $documents = collect(range(1, 4))->flatMap(fn (int $quarter) => $this->accounting->rows($year, $quarter))->groupBy('month');

        foreach ($book['months'] as $month) {
            foreach ($documents->get($month['month'], []) as $row) {
                $rows[] = [
                    $row['date'],
                    $row['invoice_number'] ?? ($row['type'] === 'refund' ? __('Refund') : ''),
                    $row['client_name'] ?? $row['description'] ?? '',
                    $row['currency'],
                    $row['amount'],
                    $row['nbu_rate'],
                    $row['uah_amount'],
                    '',
                    '',
                ];
            }

            $rows[] = [sprintf('%d-%02d', $year, $month['month']), __('Month total'), '', '', '', '', '', $month['income'], $month['cumulative']];

            if ($month['month'] % 3 === 0) {
                $quarter = $book['quarters'][$month['quarter'] - 1];
                $rows[] = [$this->quarterLabel($month['quarter']), __('Quarter total'), '', '', '', '', '', $quarter['income'], $quarter['cumulative']];
            }
        }

        $rows[] = [__('Year'), __('Year total'), '', '', '', '', '', $book['total'], $book['total']];

        return $this->csv($profile, $rows);
    }

    /**
     * Get the header of the rows of receipts.
     *
     * @return array<int, string>
     */
    protected function rowsHeader(): array
    {
        return [
            __('Date'), __('Document'), __('Client / description'), __('Currency'), __('Amount'), __('NBU rate'), __('Income, ₴'),
            $this->taxName('single_tax'), $this->taxName('military_levy'), __('Fees'), __('Net'), __('Account'),
        ];
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
            $row['invoice_number'] ?? ($row['type'] === 'refund' ? __('Refund') : ''),
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
        $checks = fn (string $tax) => [__('standalone'), $summary[$tax]['standalone'], __('by receipts'), $summary[$tax]['hints_sum']];

        return [
            [__('Quarter income, ₴'), $summary['quarter_income']],
            [__('Year-to-date income, ₴'), $summary['ytd_income']],
            [__('Single tax owed, ₴'), $summary['single_tax']['owed'], ...$checks('single_tax')],
            [__('Military levy owed, ₴'), $summary['military_levy']['owed'], ...$checks('military_levy')],
            [__('SSC, ₴'), $summary['esv']['owed']],
            [__('Income cap, %'), $summary['cap']['percent']],
        ];
    }

    /**
     * Name a tax, as the exports do: ЄП, ВЗ, ЄСВ in Ukrainian.
     *
     * @param string $type
     *
     * @return string
     */
    protected function taxName(string $type): string
    {
        return match ($type) {
            'single_tax' => __('Single tax'),
            'military_levy' => __('Military levy'),
            default => __('SSC'),
        };
    }

    /**
     * Name a quarter, such as "III кв. 2026" or "Q3 2026", or the quarter alone ("III кв.", "Q3").
     *
     * @param int $quarter
     * @param int|null $year
     *
     * @return string
     */
    protected function quarterLabel(int $quarter, ?int $year = null): string
    {
        $replace = ['quarter' => $quarter, 'roman' => ['I', 'II', 'III', 'IV'][$quarter - 1], 'year' => $year];

        return $year === null ? __('Q:quarter', $replace) : __('Q:quarter :year', $replace);
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
