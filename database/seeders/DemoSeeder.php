<?php

namespace Database\Seeders;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Core\Models\Organization;
use Core\Models\Sequence;
use Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use PryTax\Enums\AccountKind;
use PryTax\Enums\DeclarationStatus;
use PryTax\Enums\LimitPeriod;
use PryTax\Enums\TaxType;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Account;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPaymentRequisite;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Accounts\AccountBalanceCalculator;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Invoices\InvoiceFreezer;
use PryTax\Services\Invoices\InvoiceLifecycle;
use PryTax\Services\Invoices\InvoiceWriter;
use PryTax\Services\Payments\PaymentRecorder;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use PryTax\Services\Transfers\AllocationProposer;
use PryTax\Services\Transfers\TransferRecorder;

/**
 * Class DemoSeeder.
 *
 * The sample practice of the design (storage/handoff/prytax-simplified-design-handoff.md): an organization with an
 * owner (olena@example.com), an accountant (maria@example.com) and a viewer (taras@example.com), all with the password
 * `password`, and four ФОП. Коваленко О. П. has the mock's 2026 to the kopeck: III кв. income 503 118.73 ₴, IV кв.
 * so far 352 960.01 ₴, 1 840 135.05 ₴ year-to-date, ЄП for IV кв. 17 648.00, and the ЄП balance today −18 413.69.
 * Everything is recorded through the services, with the NBU rates cached, so no request reaches the NBU.
 *
 * Run it on a fresh database: `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Seeders
 */
class DemoSeeder extends Seeder
{
    /**
     * NBU rates of the receipts, transfers and fees of the sample data.
     *
     * @var array<string, array<string, string>>
     */
    protected const array RATES = [
        'USD' => [
            '2025-11-06' => '41.3000', '2025-11-10' => '41.3200', '2025-11-12' => '41.3300', '2025-12-05' => '41.5500',
            '2025-12-09' => '41.5600', '2025-12-11' => '41.5800', '2026-01-07' => '41.7033', '2026-01-20' => '41.7400',
            '2026-01-26' => '41.7500', '2026-02-06' => '41.6317', '2026-02-15' => '41.6100', '2026-03-06' => '41.4134',
            '2026-03-10' => '41.4000', '2026-04-07' => '41.3560', '2026-04-10' => '41.3500', '2026-04-14' => '41.4000',
            '2026-05-06' => '41.3478', '2026-05-11' => '41.3600', '2026-06-05' => '41.6526', '2026-06-09' => '41.6600',
            '2026-07-07' => '41.8710', '2026-07-10' => '41.8600', '2026-07-14' => '41.8500', '2026-08-06' => '41.7520',
            '2026-08-07' => '41.7500', '2026-09-04' => '41.4410', '2026-09-10' => '41.4300', '2026-09-15' => '41.4500',
            '2026-10-05' => '42.2297', '2026-10-08' => '42.1830', '2026-10-14' => '42.1000', '2026-10-19' => '42.0150',
        ],
        'EUR' => [
            '2026-01-07' => '48.1200', '2026-02-06' => '48.0050', '2026-03-06' => '47.8800', '2026-04-07' => '47.6000',
            '2026-05-06' => '47.5100', '2026-06-05' => '47.7300', '2026-07-07' => '47.9500', '2026-08-06' => '47.9100',
            '2026-09-04' => '47.9900', '2026-09-22' => '48.0412', '2026-10-14' => '48.6120',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        foreach (self::RATES as $currency => $rates) {
            foreach ($rates as $date => $rate) {
                ExchangeRate::query()->updateOrCreate(
                    ['currency' => $currency, 'rate_date' => $date],
                    ['rate' => $rate, 'fetched_at' => now()],
                );
            }
        }

        $organization = Organization::query()->create(['slug' => 'praktyka', 'name' => 'Практика Коваленко']);
        $organization->forceFill(['activated_at' => now()])->save();

        $this->user($organization, 'Олена Коваленко', 'olena@example.com', 'owner');
        $this->user($organization, 'Марія Шевчук', 'maria@example.com', 'accountant');
        $this->user($organization, 'Тарас Коваленко', 'taras@example.com', 'viewer');

        $profiles = AlertSync::paused(fn () => [
            $this->kovalenko($organization),
            $this->melnyk($organization),
            $this->bondar($organization),
            $this->savchuk($organization),
        ]);

        foreach ($profiles as $profile) {
            app(AlertSync::class)->sync($profile);
        }
    }

    /**
     * Create a user of the organization with the role and the password `password`.
     *
     * @param Organization $organization
     * @param string $name
     * @param string $email
     * @param string $role
     *
     * @return User
     */
    protected function user(Organization $organization, string $name, string $email, string $role): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => 'password', 'timezone' => 'Europe/Kyiv', 'locale' => 'uk']);
        $user->forceFill([
            'organization_id' => $organization->id,
            'email_verified_at' => now(),
            'password_updated_at' => now(),
        ])->save();

        return $user->assignRole($role);
    }

    /**
     * Create a profile of the organization with the tax periods and ЄСВ months of its years.
     *
     * @param Organization $organization
     * @param array $attributes
     * @param array<int, int> $years
     *
     * @return Profile
     */
    protected function profile(Organization $organization, array $attributes, array $years = [2026]): Profile
    {
        $profile = new Profile($attributes);
        $profile->organization_id = $organization->id;
        // The practice started using the app with its first year, so its taxes are tracked from then (TaxTracking)
        $profile->created_at = min($years) . '-01-01 09:00:00';
        $profile->save();

        CurrentProfile::run($profile, function () use ($profile, $years) {
            foreach ($years as $year) {
                for ($quarter = 1; $quarter <= 4; $quarter++) {
                    app(DeadlineScheduler::class)->ensure($profile, $year, $quarter);
                }

                app(EsvObligationScheduler::class)->ensureYear($profile, $year);
            }
        });

        return $profile;
    }

    /**
     * Коваленко Олена Петрівна: the mock's ФОП, with its 2026 to the kopeck.
     *
     * @param Organization $organization
     *
     * @return Profile
     */
    protected function kovalenko(Organization $organization): Profile
    {
        $profile = $this->profile($organization, [
            'full_name' => 'Коваленко Олена Петрівна',
            'full_name_en' => 'Olena Kovalenko',
            'tax_id' => '3214704821',
            'registered_at' => '2023-04-01',
            'address' => 'вул. Хрещатик, 1, кв. 1, Київ, 01001',
            'address_en' => '1 Khreshchatyk St., apt. 1, Kyiv, 01001, Ukraine',
            'email' => 'olena@example.com',
            'phone' => '+380501234567',
            'default_currency' => 'USD',
            'locale' => 'en',
        ], [2025, 2026]);

        CurrentProfile::run($profile, function () use ($profile) {
            $wiseUsd = $this->account($profile, 'Wise USD', AccountKind::Platform, 'USD', ['bank_name' => 'Wise', 'account_number' => '8310004410', 'details' => [['label' => 'ACH routing', 'value' => '026073150012']], 'sort_order' => 1]);
            $wiseEur = $this->account($profile, 'Wise EUR', AccountKind::Platform, 'EUR', ['bank_name' => 'Wise', 'iban' => 'BE71967000004410', 'swift' => 'TRWIBEB1XXX', 'sort_order' => 2]);
            $payoneer = $this->account($profile, 'Payoneer USD', AccountKind::Platform, 'USD', [
                'bank_name' => 'Payoneer', 'account_number' => '5000000089',
                'limit_amount' => '10000.00', 'limit_period' => LimitPeriod::Year, 'sort_order' => 3,
            ]);
            $bankUah = $this->account($profile, 'ПриватБанк ФОП', AccountKind::FopBank, 'UAH', ['bank_name' => 'АТ КБ «ПриватБанк»', 'iban' => 'UA213052990000026001234567890', 'sort_order' => 4]);
            $bankUsd = $this->account($profile, 'ПриватБанк ФОП', AccountKind::FopBank, 'USD', [
                'bank_name' => 'JSC CB PrivatBank', 'iban' => 'UA493052990000026002234567890', 'swift' => 'PBANUA2X',
                'limit_amount' => '50000.00', 'limit_period' => LimitPeriod::Month, 'sort_order' => 5,
            ]);
            $this->account($profile, 'Mercury USD', AccountKind::ForeignBank, 'USD', ['bank_name' => 'Mercury', 'is_active' => false, 'sort_order' => 6]);
            $this->account($profile, 'Monobank картка', AccountKind::Personal, 'UAH', ['bank_name' => 'Monobank', 'sort_order' => 7]);

            $profile->updateSettings([
                'taxes.payment_account_ids' => [$bankUah->id, $bankUsd->id],
                'taxes.default_account_id' => $bankUah->id,
            ]);

            [$brightline, $brightlineContract] = $this->brightline($profile);
            [$nordwave, $nordwaveContract] = $this->client($profile, [
                'name' => 'Nordwave GmbH', 'abbreviation' => 'NW', 'legal_name' => 'Nordwave GmbH', 'country' => 'DE', 'currency' => 'EUR', 'vat_number' => 'DE123456789',
                'other_note_enabled' => true, 'other_note' => 'Reverse charge: the services are not subject to Ukrainian VAT.',
            ], ['name' => 'Framework Agreement', 'number' => 'NW-FA-2024', 'started_at' => '2024-02-01', 'currency' => 'EUR', 'payment_terms_days' => 30]);
            [$kestrel, $kestrelContract] = $this->client($profile, [
                'name' => 'Kestrel Studio', 'abbreviation' => 'KS', 'legal_name' => 'Kestrel Studio Ltd', 'country' => 'GB', 'currency' => 'USD',
            ], ['name' => 'Service Agreement', 'number' => 'KS-2025', 'started_at' => '2025-06-01', 'ends_at' => '2026-11-15', 'currency' => 'USD', 'payment_terms_days' => 4]);

            $proto = $brightline->projects()->where('code', 'PROTO')->first();
            $billable = $brightline->chargeTypes()->where('name', 'Billable')->first();
            $hours = fn (string $quantity) => [['project_id' => $proto->id, 'charge_type_id' => $billable->id, 'quantity' => $quantity]];
            $fixed = fn (string $price, string $description) => [['unit' => 'fixed', 'quantity' => '1', 'unit_price' => $price, 'description' => $description]];

            // Brightline: monthly invoices, each paid early in the next month
            $brightlineInvoices = [
                ['2025-10-01', '2025-10-31', $hours('84.00'), ['2025-11-06', $wiseUsd, '3765.00']],
                ['2025-11-01', '2025-11-30', $hours('86.00'), ['2025-12-05', $wiseUsd, '3855.00']],
                ['2025-12-01', '2025-12-31', $hours('85.90'), ['2026-01-07', $payoneer, '3826.85']],
                ['2026-01-01', '2026-01-31', $hours('84.75'), ['2026-02-06', $payoneer, '3775.61']],
                ['2026-02-01', '2026-02-28', $hours('89.20'), ['2026-03-06', $wiseUsd, '3999.00']],
                ['2026-03-01', '2026-03-31', $hours('87.65'), ['2026-04-07', $wiseUsd, '3929.25']],
                ['2026-04-01', '2026-04-30', $hours('89.97'), ['2026-05-06', $wiseUsd, '4033.65']],
                ['2026-05-01', '2026-05-31', $hours('89.27'), ['2026-06-05', $wiseUsd, '4002.15']],
                ['2026-06-01', '2026-06-30', $hours('86.50'), ['2026-07-07', $wiseUsd, '3877.50']],
                ['2026-07-01', '2026-07-31', $fixed('3806.00', 'IT services in accordance to the Service Agreement dated April 13, 2023 — July 2026'), ['2026-08-06', $wiseUsd, '3806.00']],
                ['2026-08-01', '2026-08-31', $fixed('3979.00', 'IT services in accordance to the Service Agreement dated April 13, 2023 — August 2026'), ['2026-09-04', $wiseUsd, '3964.00']],
                ['2026-09-01', '2026-09-30', $hours('86.50'), ['2026-10-05', $wiseUsd, '3877.50']],
            ];

            $receipts = [];

            foreach ($brightlineInvoices as [$start, $end, $items, [$date, $account, $received]]) {
                $invoice = $this->invoice($profile, $brightline, $brightlineContract, $wiseUsd, $start, $end, $items);
                $receipts[$date] = $this->receipt($profile, $invoice, $account, $invoice->total, $received, $date, pay: true);
            }

            // IV кв.: one invoice partly paid, one draft
            $october = $this->invoice($profile, $brightline, $brightlineContract, $wiseUsd, '2026-10-01', '2026-10-14', $hours('86.50'), sentAt: '2026-10-15');
            $receipts['2026-10-19'] = $this->receipt($profile, $october, $wiseUsd, '3100.00', '3100.00', '2026-10-19');
            $this->invoice($profile, $brightline, $brightlineContract, $wiseUsd, '2026-10-15', '2026-10-31', $hours('76.90'), freeze: false);

            // Nordwave: three earlier invoices elsewhere, a quarter's invoice and an external one
            Sequence::setNext($profile, 'invoices:client:' . $nordwave->id, 4);
            $nordwaveQ3 = $this->invoice($profile, $nordwave, $nordwaveContract, $wiseEur, '2026-07-01', '2026-09-30', $fixed('1200.00', 'Software development services, Q3 2026'));
            $receipts['2026-10-14'] = $this->receipt($profile, $nordwaveQ3, $wiseEur, '1200.00', '1200.00', '2026-10-14', pay: true);
            $external = $this->externalInvoice($profile, $nordwave, $wiseEur, 'NW-2026-031', '2026-08-01', '2026-08-31', '340.00');
            $receipts['2026-09-22'] = $this->receipt($profile, $external, $wiseEur, '340.00', '340.00', '2026-09-22', pay: true);

            // Kestrel: six earlier invoices elsewhere, a cancelled one and an overdue one
            Sequence::setNext($profile, 'invoices:client:' . $kestrel->id, 6);
            $cancelled = $this->invoice($profile, $kestrel, $kestrelContract, $payoneer, '2026-07-16', '2026-08-15', $fixed('420.00', 'Design support, July 2026'), sentAt: '2026-08-16');
            app(InvoiceLifecycle::class)->cancel($profile, $cancelled, '2026-08-20');
            $this->invoice($profile, $kestrel, $kestrelContract, $payoneer, '2026-09-16', '2026-10-15', $fixed('640.00', 'Design support, September–October 2026'));

            // Kestrel paid an advance for November without an invoice: a record for the books only (design.md §4.2)
            app(PaymentRecorder::class)->record($profile, [
                'virtual_invoice' => [
                    'client_id' => $kestrel->id,
                    'contract_id' => $kestrelContract->id,
                    'period_start' => '2026-11-01',
                    'period_end' => '2026-11-15',
                    'notes' => 'Аванс за листопад',
                ],
                'amount' => '500.00',
                'received_to_account_id' => $wiseUsd->id,
                'received_amount' => '500.00',
                'received_at' => '2026-10-19',
            ]);

            $this->kovalenkoTransfers($profile, $receipts, $wiseUsd, $payoneer, $bankUsd, $bankUah);
            $this->kovalenkoTaxes($profile, $receipts, $bankUah, $bankUsd);

            // The tracked balance of the UAH account, as the mock's screen shows it
            $flows = app(AccountBalanceCalculator::class)->flows($bankUah, CarbonImmutable::parse('2026-01-01'));
            $bankUah->openingBalances()->create([
                'as_of' => '2026-01-01',
                'amount' => bcsub('286410.37', bcsub($flows['inbound'], $flows['outbound'], 2), 2),
                'profile_id' => $profile->id,
            ]);
        });

        return $profile;
    }

    /**
     * Brightline Labs LLC with its contract, rates, projects and charge types.
     *
     * @param Profile $profile
     *
     * @return array{0: Client, 1: Contract}
     */
    protected function brightline(Profile $profile): array
    {
        [$client, $contract] = $this->client($profile, [
            'name' => 'Brightline Labs LLC', 'abbreviation' => 'BL', 'legal_name' => 'Brightline Labs, LLC', 'country' => 'US', 'currency' => 'USD',
            'tax_id' => '84-1234567', 'email' => 'ap@brightline.example',
            'address' => ['lines' => ['500 Market St, Suite 200'], 'city' => 'San Francisco, CA', 'zip' => '94105'],
        ], [
            'name' => 'Service Agreement', 'started_at' => '2023-04-13', 'currency' => 'USD', 'payment_terms_days' => 14,
            'description_template' => 'IT services in accordance to the {name} dated {started_at:F j, Y}',
        ]);

        foreach ([['PROTO', 'Prototype platform', true], ['CORE', 'Core API', true], ['LEGACY', 'Legacy app', false]] as [$code, $name, $active]) {
            $client->projects()->create(['code' => $code, 'name' => $name, 'is_active' => $active, 'profile_id' => $profile->id]);
        }

        $billable = $client->chargeTypes()->create(['name' => 'Billable', 'profile_id' => $profile->id]);
        $overhead = $client->chargeTypes()->create(['name' => 'Overhead', 'profile_id' => $profile->id]);

        $contract->rates()->create(['effective_from' => '2023-04-13', 'hourly_rate' => '40.00']);
        $contract->rates()->create(['effective_from' => '2025-01-01', 'charge_type_id' => $billable->id, 'hourly_rate' => '45.00']);
        $contract->rates()->create(['effective_from' => '2026-01-01', 'charge_type_id' => $overhead->id, 'hourly_rate' => '35.00']);

        return [$client, $contract];
    }

    /**
     * The transfers of Коваленко's money: platforms to the ФОП USD account, and sales of USD into UAH.
     *
     * @param Profile $profile
     * @param array<string, Payment> $receipts
     * @param Account $wiseUsd
     * @param Account $payoneer
     * @param Account $bankUsd
     * @param Account $bankUah
     *
     * @return void
     */
    protected function kovalenkoTransfers(Profile $profile, array $receipts, Account $wiseUsd, Account $payoneer, Account $bankUsd, Account $bankUah): void
    {
        $recorder = app(TransferRecorder::class);
        $transfer = fn (Account $from, Account $to, string $sent, string $received, string $date, array $allocations) => $recorder->record($profile, [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount_sent' => $sent,
            'amount_received' => $received,
            'received_at' => $date,
            'sent_at' => $date,
            'allocations' => array_map(fn (array $allocation) => ['payment_id' => $allocation[0]->id, 'amount' => $allocation[1]], $allocations),
        ]);

        // Sales of USD on the ФОП account, below the NBU rate
        $proposer = app(AllocationProposer::class);
        $sell = function (string $sent, string $received, string $date) use ($recorder, $profile, $bankUsd, $bankUah, $proposer) {
            $recorder->record($profile, [
                'from_account_id' => $bankUsd->id,
                'to_account_id' => $bankUah->id,
                'amount_sent' => $sent,
                'amount_received' => $received,
                'received_at' => $date,
                'allocations' => $proposer->propose($bankUsd->fresh(), $sent)['fifo'],
            ]);
        };

        // 2025's receipts went on to the ФОП account and were sold
        $transfer($wiseUsd, $bankUsd, '3765.00', '3754.50', '2025-11-10', [[$receipts['2025-11-06'], '3765.00']]);
        $sell('3754.50', '154241.39', '2025-11-12');
        $transfer($wiseUsd, $bankUsd, '3855.00', '3844.50', '2025-12-09', [[$receipts['2025-12-05'], '3855.00']]);
        $sell('3844.50', '158742.41', '2025-12-11');

        // Payoneer keeps 312.40 of January's receipt (dismissed), the rest goes on
        $transfer($payoneer, $bankUsd, '3514.45', '3480.00', '2026-01-20', [[$receipts['2026-01-07'], '3514.45']]);
        $transfer($payoneer, $bankUsd, '3775.61', '3737.85', '2026-02-15', [[$receipts['2026-02-06'], '3775.61']]);
        $receipts['2026-01-07']->forceFill(['repatriation_ack_at' => '2026-04-02 10:00:00'])->save();

        $transfer($wiseUsd, $bankUsd, '3999.00', '3988.50', '2026-03-10', [[$receipts['2026-03-06'], '3999.00']]);
        $transfer($wiseUsd, $bankUsd, '3929.25', '3918.75', '2026-04-10', [[$receipts['2026-04-07'], '3929.25']]);
        $transfer($wiseUsd, $bankUsd, '4033.65', '4023.15', '2026-05-11', [[$receipts['2026-05-06'], '4033.65']]);
        $transfer($wiseUsd, $bankUsd, '4002.15', '3991.65', '2026-06-09', [[$receipts['2026-06-05'], '4002.15']]);
        $transfer($wiseUsd, $bankUsd, '3877.50', '3865.30', '2026-07-10', [[$receipts['2026-07-07'], '3877.50']]);
        $transfer($wiseUsd, $bankUsd, '3806.00', '3793.10', '2026-08-07', [[$receipts['2026-08-06'], '3806.00']]);
        // 1 240.00 of September's receipt stays on Wise past the quarter: a repatriation notice
        $transfer($wiseUsd, $bankUsd, '2724.00', '2714.50', '2026-09-10', [[$receipts['2026-09-04'], '2724.00']]);

        // 2026's sales of USD on the ФОП account
        $sell('3480.00', '144420.00', '2026-01-26');
        $sell('7726.35', '317938.30', '2026-04-14');
        $sell('11933.55', '496427.68', '2026-07-14');
        $sell('5322.90', '219302.48', '2026-09-15');
        $sell('2400.00', '100680.00', '2026-10-08');
    }

    /**
     * The taxes of Коваленко (design.md §8.4): ЄП and ВЗ paid per receipt (Nordwave's external invoice from the USD
     * account), ЄСВ by month on the 19th of the next one, and IV кв.'s first receipt with ЄП sent and ВЗ paid.
     *
     * @param Profile $profile
     * @param array<string, Payment> $receipts
     * @param Account $bankUah
     * @param Account $bankUsd
     *
     * @return void
     */
    protected function kovalenkoTaxes(Profile $profile, array $receipts, Account $bankUah, Account $bankUsd): void
    {
        foreach (['2025-11-06', '2025-12-05'] as $date) {
            $this->payReceiptTaxes($profile, $receipts[$date], $bankUah, '2026-02-10');
        }

        $this->payEsvMonthly($profile, $bankUah, 2025, range(1, 12));

        foreach ([1 => 'single_tax', 2 => 'military_levy', 3 => 'social_contribution'] as $type) {
            TaxOpeningBalance::query()->create([
                'tax_type' => $type,
                'as_of' => '2026-01-01',
                'amount' => $type === 'single_tax' ? '51.00' : '0.00',
                'profile_id' => $profile->id,
            ]);
        }

        foreach (['2026-01-07', '2026-02-06', '2026-03-06'] as $date) {
            $this->payReceiptTaxes($profile, $receipts[$date], $bankUah, '2026-05-15');
        }

        foreach (['2026-04-07', '2026-05-06', '2026-06-05'] as $date) {
            $this->payReceiptTaxes($profile, $receipts[$date], $bankUah, '2026-08-14');
        }

        foreach (['2026-07-07' => '2026-07-10', '2026-08-06' => '2026-08-10', '2026-09-04' => '2026-09-10'] as $date => $paidAt) {
            $this->payReceiptTaxes($profile, $receipts[$date], $bankUah, $paidAt);
        }

        // From the USD account: the bank sells the dollars at its own rate, so what left it is typed by hand
        $this->payReceiptTaxes($profile, $receipts['2026-09-22'], $bankUsd, '2026-09-25', '41.2000');
        $this->payEsvMonthly($profile, $bankUah, 2026, range(1, 9));

        $recorder = app(TaxPaymentRecorder::class);
        $october = $receipts['2026-10-05'];

        foreach ([[TaxType::SingleTax, 'sent'], [TaxType::MilitaryLevy, 'paid']] as [$type, $status]) {
            $recorder->record($profile, [
                'tax_type' => $type, 'allocation' => 'payment', 'payment_id' => $october->id,
                'status' => $status, 'paid_at' => '2026-10-12', 'paid_from_account_id' => $bankUah->id,
            ]);
        }

        $this->declared($profile, [2025 => [1, 2, 3, 4], 2026 => [1, 2]]);
        $this->requisites($profile, 'Київ');
    }

    /**
     * Мельник Андрій Сергійович: a smaller USD ФОП, with half of IV кв. invoiced and received.
     *
     * @param Organization $organization
     *
     * @return Profile
     */
    protected function melnyk(Organization $organization): Profile
    {
        $profile = $this->profile($organization, [
            'full_name' => 'Мельник Андрій Сергійович', 'full_name_en' => 'Andrii Melnyk', 'tax_id' => '3011201937',
            'registered_at' => '2024-01-10', 'email' => 'andrii.melnyk@example.com', 'default_currency' => 'USD',
        ]);

        CurrentProfile::run($profile, function () use ($profile) {
            $wise = $this->account($profile, 'Wise USD', AccountKind::Platform, 'USD', ['bank_name' => 'Wise']);
            $bank = $this->account($profile, 'Монобанк ФОП', AccountKind::FopBank, 'UAH', ['bank_name' => 'АТ «Універсал Банк»', 'iban' => 'UA563220010000026001234560001']);
            [$client, $contract] = $this->client($profile, ['name' => 'Orbit Analytics Inc', 'abbreviation' => 'OA', 'country' => 'US', 'currency' => 'USD'], [
                'name' => 'Consulting Agreement', 'started_at' => '2024-01-15', 'currency' => 'USD', 'payment_terms_days' => 10,
            ]);
            $items = fn () => [['unit' => 'fixed', 'quantity' => '1', 'unit_price' => '2150.00', 'description' => 'Data engineering consulting']];

            $dates = ['2026-01-07', '2026-02-06', '2026-03-06', '2026-04-07', '2026-05-06', '2026-06-05', '2026-07-07', '2026-08-06', '2026-09-04', '2026-10-14'];

            foreach ($dates as $index => $date) {
                $start = CarbonImmutable::parse($date)->subMonthNoOverflow()->startOfMonth();
                $issue = $index === 9 ? CarbonImmutable::parse('2026-10-05') : $start->endOfMonth();
                $invoice = $this->invoice($profile, $client, $contract, $wise, $start->toDateString(), $issue->toDateString(), $items());
                $payment = $this->receipt($profile, $invoice, $wise, '2150.00', '2150.00', $date, pay: true);
                app(TransferRecorder::class)->record($profile, [
                    'from_account_id' => $wise->id, 'to_account_id' => $bank->id, 'amount_sent' => '2150.00',
                    'amount_received' => (string) round(2150 * ((float) self::RATES['USD'][$date] - 0.3), 2),
                    'received_at' => $date, 'allocations' => [['payment_id' => $payment->id, 'amount' => '2150.00']],
                ]);
            }

            $this->invoice($profile, $client, $contract, $wise, '2026-10-06', '2026-10-15', $items(), sentAt: '2026-10-16');
            $this->payTaxesInFull($profile, $bank, [1, 2, 3]);
            $this->declared($profile, [2026 => [1, 2]]);
        });

        return $profile;
    }

    /**
     * Бондар Ірина Вікторівна: a large EUR ФОП at 80 % of the income cap, whose II кв. needs an уточнююча декларація.
     *
     * @param Organization $organization
     *
     * @return Profile
     */
    protected function bondar(Organization $organization): Profile
    {
        $profile = $this->profile($organization, [
            'full_name' => 'Бондар Ірина Вікторівна', 'full_name_en' => 'Iryna Bondar', 'tax_id' => '2955401102',
            'registered_at' => '2022-09-01', 'email' => 'iryna.bondar@example.com', 'default_currency' => 'EUR',
        ]);

        CurrentProfile::run($profile, function () use ($profile) {
            $wise = $this->account($profile, 'Wise EUR', AccountKind::Platform, 'EUR', ['bank_name' => 'Wise']);
            $bank = $this->account($profile, 'Райффайзен ФОП', AccountKind::FopBank, 'UAH', ['bank_name' => 'АТ «Райффайзен Банк»', 'iban' => 'UA813800050000026001234560002']);
            [$client, $contract] = $this->client($profile, ['name' => 'Lumen Systems GmbH', 'abbreviation' => 'LS', 'country' => 'DE', 'currency' => 'EUR'], [
                'name' => 'Rahmenvertrag', 'number' => 'LS-2022-07', 'started_at' => '2022-09-01', 'currency' => 'EUR', 'payment_terms_days' => 14,
            ]);

            foreach (array_keys(self::RATES['EUR']) as $date) {
                if ($date === '2026-09-22') {
                    continue;
                }

                $amount = $date === '2026-10-14' ? '18400.00' : '18100.00';
                $start = CarbonImmutable::parse($date)->subMonthNoOverflow()->startOfMonth();
                $invoice = $this->invoice($profile, $client, $contract, $wise, $start->toDateString(), $start->endOfMonth()->toDateString(), [
                    ['unit' => 'fixed', 'quantity' => '1', 'unit_price' => $amount, 'description' => 'Software architecture services'],
                ]);
                $payment = $this->receipt($profile, $invoice, $wise, $amount, $amount, $date, pay: true);
                app(TransferRecorder::class)->record($profile, [
                    'from_account_id' => $wise->id, 'to_account_id' => $bank->id, 'amount_sent' => $amount,
                    'amount_received' => (string) round((float) $amount * ((float) self::RATES['EUR'][$date] - 0.35), 2),
                    'received_at' => $date, 'allocations' => [['payment_id' => $payment->id, 'amount' => $amount]],
                ]);
            }

            $this->invoice($profile, $client, $contract, $wise, '2026-10-01', '2026-10-15', [
                ['unit' => 'fixed', 'quantity' => '1', 'unit_price' => '7600.00', 'description' => 'Software architecture services'],
            ], sentAt: '2026-10-16');

            $this->payTaxesInFull($profile, $bank, [1, 2, 3]);
            $this->declared($profile, [2026 => [1, 2]]);
            TaxPeriod::query()->where('year', 2026)->where('quarter', 2)->first()
                ->forceFill(['declaration_status' => DeclarationStatus::Corrected, 'notes' => '15.09.2026: виправлено курс надходження 06.05.2026'])
                ->save();
        });

        return $profile;
    }

    /**
     * Савчук Тарас Олегович: a ФОП closed on 31.03.2026, which drops out of the boards.
     *
     * @param Organization $organization
     *
     * @return Profile
     */
    protected function savchuk(Organization $organization): Profile
    {
        return $this->profile($organization, [
            'full_name' => 'Савчук Тарас Олегович', 'full_name_en' => 'Taras Savchuk', 'tax_id' => '3378905510',
            'registered_at' => '2021-05-20', 'is_active' => false, 'closed_at' => '2026-03-31',
        ]);
    }

    /**
     * Create an account of the profile.
     *
     * @param Profile $profile
     * @param string $name
     * @param AccountKind $kind
     * @param string $currency
     * @param array $attributes
     *
     * @return Account
     */
    protected function account(Profile $profile, string $name, AccountKind $kind, string $currency, array $attributes = []): Account
    {
        $account = new Account(['name' => $name, 'kind' => $kind, 'currency' => $currency, 'holder_name' => $profile->full_name_en, ...$attributes]);
        $account->profile_id = $profile->id;
        $account->save();

        return $account;
    }

    /**
     * Create a client of the profile with its contract.
     *
     * @param Profile $profile
     * @param array $attributes
     * @param array $contract
     *
     * @return array{0: Client, 1: Contract}
     */
    protected function client(Profile $profile, array $attributes, array $contract): array
    {
        $client = new Client($attributes);
        $client->profile_id = $profile->id;
        $client->save();

        $model = new Contract([
            'description_template' => 'IT services in accordance to the {name} {number} dated {started_at:F j, Y}',
            ...$contract,
        ]);
        $model->forceFill(['profile_id' => $profile->id, 'client_id' => $client->id])->save();

        return [$client, $model];
    }

    /**
     * Create an invoice through the writer, and freeze and send it, unless asked otherwise.
     *
     * @param Profile $profile
     * @param Client $client
     * @param Contract $contract
     * @param Account $account
     * @param string $start
     * @param string $end
     * @param array $items
     * @param bool $freeze
     * @param string|null $sentAt
     *
     * @return Invoice
     */
    protected function invoice(
        Profile $profile,
        Client $client,
        Contract $contract,
        Account $account,
        string $start,
        string $end,
        array $items,
        bool $freeze = true,
        ?string $sentAt = null
    ): Invoice {
        $invoice = app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'receiving_account_id' => $account->id,
            'period_start' => $start,
            'period_end' => $end,
            'items' => $items,
        ]);

        if (!$freeze) {
            return $invoice;
        }

        $invoice = app(InvoiceFreezer::class)->freeze($profile, $invoice);

        return $sentAt ? app(InvoiceLifecycle::class)->send($profile, $invoice, $sentAt) : $invoice;
    }

    /**
     * Create an external invoice with an uploaded PDF, and freeze it.
     *
     * @param Profile $profile
     * @param Client $client
     * @param Account $account
     * @param string $number
     * @param string $start
     * @param string $end
     * @param string $total
     *
     * @return Invoice
     */
    protected function externalInvoice(Profile $profile, Client $client, Account $account, string $number, string $start, string $end, string $total): Invoice
    {
        $invoice = app(InvoiceWriter::class)->create($profile, [
            'source' => 'external',
            'client_id' => $client->id,
            'receiving_account_id' => $account->id,
            'number' => $number,
            'period_start' => $start,
            'period_end' => $end,
            'total' => $total,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, Pdf::loadHTML("<h1>Invoice $number</h1><p>Total: $total {$account->currency}</p>")->output());

        $file = $invoice->attachFile(new UploadedFile($path, "$number.pdf", 'application/pdf', null, true), null, Invoice::UPLOADED_COLLECTION);
        $invoice->forceFill(['primary_file_id' => $file->id])->save();

        return app(InvoiceFreezer::class)->freeze($profile, $invoice);
    }

    /**
     * Record a receipt of the invoice, and mark it paid on its date, when asked.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param Account $account
     * @param string $amount
     * @param string $received
     * @param string $date
     * @param bool $pay
     *
     * @return Payment
     */
    protected function receipt(Profile $profile, Invoice $invoice, Account $account, string $amount, string $received, string $date, bool $pay = false): Payment
    {
        $payment = app(PaymentRecorder::class)->record($profile, [
            'kind' => 'invoice',
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'received_to_account_id' => $account->id,
            'received_amount' => $received,
            'received_at' => $date,
        ]);

        if ($pay) {
            $invoice = $invoice->fresh();

            if ($invoice->status->value === 'ready') {
                $invoice = app(InvoiceLifecycle::class)->send($profile, $invoice, $invoice->issued_at->addDay()->toDateString());
            }

            app(InvoiceLifecycle::class)->pay($profile, $invoice, $date);
        }

        return $payment;
    }

    /**
     * Pay the taxes of 2026's quarters in full: ЄП and ВЗ of each receipt four days before the quarter's deadline
     * (or today, while it is ahead), and ЄСВ by month.
     *
     * @param Profile $profile
     * @param Account $account
     * @param array<int, int> $quarters
     *
     * @return void
     */
    protected function payTaxesInFull(Profile $profile, Account $account, array $quarters): void
    {
        foreach ($quarters as $quarter) {
            $period = TaxPeriod::query()->where('year', 2026)->where('quarter', $quarter)->first();
            $paidAt = min($period->tax_due_at->subDays(4)->toDateString(), now()->toDateString());
            $receipts = Payment::query()->where('period_year', 2026)->where('period_quarter', $quarter)->where('kind', 'invoice')->get();

            foreach ($receipts as $receipt) {
                $this->payReceiptTaxes($profile, $receipt, $account, $paidAt);
            }

            $this->payEsvMonthly($profile, $account, 2026, range($quarter * 3 - 2, $quarter * 3));
        }
    }

    /**
     * Pay the receipt's ЄП and ВЗ at its amounts; from a foreign-currency account at the bank's rate, which gives
     * the amount that left it.
     *
     * @param Profile $profile
     * @param Payment $receipt
     * @param Account $account
     * @param string $paidAt
     * @param string|null $bankRate Hryvnias per unit of a foreign-currency account's currency.
     *
     * @return void
     */
    protected function payReceiptTaxes(Profile $profile, Payment $receipt, Account $account, string $paidAt, ?string $bankRate = null): void
    {
        foreach ([[TaxType::SingleTax, $receipt->single_tax_uah], [TaxType::MilitaryLevy, $receipt->military_levy_uah]] as [$type, $amount]) {
            app(TaxPaymentRecorder::class)->record($profile, [
                'tax_type' => $type,
                'allocation' => 'payment',
                'payment_id' => $receipt->id,
                'status' => 'paid',
                'paid_at' => $paidAt,
                'paid_from_account_id' => $account->id,
                'original_amount' => $bankRate === null ? null : bcdiv($amount, $bankRate, 2),
            ]);
        }
    }

    /**
     * Pay ЄСВ of the months of a year, each on the 19th of the next month (or today, while that is ahead), at the
     * month's share of its quarter's minimum.
     *
     * @param Profile $profile
     * @param Account $account
     * @param int $year
     * @param array<int, int> $months
     *
     * @return void
     */
    protected function payEsvMonthly(Profile $profile, Account $account, int $year, array $months): void
    {
        foreach ($months as $month) {
            $quarter = intdiv($month - 1, 3) + 1;
            $owed = app(TaxLiabilityCalculator::class)->forQuarter($profile, $year, $quarter)['esv']['owed'];
            $share = bcdiv($owed, '3', 2);
            // The quarter's last month takes what the shares leave
            $amount = $month % 3 === 0 ? bcsub($owed, bcmul($share, '2', 2), 2) : $share;
            $due = CarbonImmutable::create($year, $month, 1)->addMonthNoOverflow()->setDay(19);

            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            app(TaxPaymentRecorder::class)->record($profile, [
                'tax_type' => TaxType::SocialContribution,
                'allocation' => 'month',
                'period_year' => $year,
                'period_month' => $month,
                'amount' => $amount,
                'status' => 'paid',
                'paid_at' => min($due->toDateString(), now()->toDateString()),
                'paid_from_account_id' => $account->id,
            ]);
        }
    }

    /**
     * Mark the quarters' declarations as accepted, which locks them.
     *
     * @param Profile $profile
     * @param array<int, array<int, int>> $quarters By year.
     *
     * @return void
     */
    protected function declared(Profile $profile, array $quarters): void
    {
        foreach ($quarters as $year => $list) {
            foreach ($list as $quarter) {
                $period = TaxPeriod::query()->where('year', $year)->where('quarter', $quarter)->first();
                $period->forceFill([
                    'declaration_status' => DeclarationStatus::Accepted,
                    'declaration_submitted_at' => $period->declaration_due_at->subDays(5),
                    'locked_at' => $period->declaration_due_at->subDays(5),
                ])->save();
            }
        }
    }

    /**
     * Create the budget requisites of the three taxes, with placeholders as the mock's.
     *
     * @param Profile $profile
     * @param string $city
     *
     * @return void
     */
    protected function requisites(Profile $profile, string $city): void
    {
        foreach ([
            ['single_tax', 'UA028999980334139811000026001', "ГУК у м. $city/18050400", '*;101;{tax_id};Єдиний податок, {period};{full_name}'],
            ['military_levy', 'UA078999980314040611000026001', "ГУК у м. $city/11011000", '*;101;{tax_id};Військовий збір, {period};{full_name}'],
            ['social_contribution', 'UA628999980000355619201022001', "ГУ ДПС у м. $city", '*;101;{tax_id};Єдиний внесок, {period};{full_name}'],
        ] as [$type, $iban, $recipient, $purpose]) {
            TaxPaymentRequisite::query()->create([
                'tax_type' => $type, 'iban' => $iban, 'recipient_name' => $recipient, 'recipient_code' => '37993783',
                'purpose_template' => $purpose, 'effective_from' => '2026-01-01', 'profile_id' => $profile->id,
            ]);
        }
    }
}
