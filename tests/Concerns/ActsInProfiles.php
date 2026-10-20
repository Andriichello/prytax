<?php

namespace Tests\Concerns;

use Closure;
use Core\Models\Organization;
use Core\Models\User;
use Laravel\Sanctum\Sanctum;
use PryTax\Enums\DeclarationStatus;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Account;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Invoices\InvoiceFreezer;
use PryTax\Services\Invoices\InvoiceWriter;
use PryTax\Services\Payments\PaymentRecorder;
use PryTax\Services\Taxes\DeadlineScheduler;

/**
 * Trait ActsInProfiles.
 *
 * Helpers of tests, which act in a profile (ФОП) of an organization: its users with a role, records created in the
 * profile, and cached NBU rates, so no test calls the NBU.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Concerns
 */
trait ActsInProfiles
{
    /**
     * Create a user of the organization with the role.
     *
     * @param Organization $organization
     * @param string $role
     *
     * @return User
     */
    protected function userOf(Organization $organization, string $role = 'owner'): User
    {
        return User::factory()->create(['organization_id' => $organization->id])->assignRole($role);
    }

    /**
     * Create a profile of a new organization, unless one is given.
     *
     * @param Organization|null $organization
     * @param array $attributes
     *
     * @return Profile
     */
    protected function createProfile(?Organization $organization = null, array $attributes = []): Profile
    {
        return Profile::factory()->create([
            'organization_id' => ($organization ?? Organization::factory()->create())->id,
            ...$attributes,
        ]);
    }

    /**
     * Sign in as a user of the profile's organization with the role.
     *
     * @param Profile $profile
     * @param string $role
     *
     * @return User
     */
    protected function actAs(Profile $profile, string $role = 'owner'): User
    {
        $user = $this->userOf($profile->organization, $role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Run the callback in the profile, as code outside of a request does.
     *
     * @template TResult
     *
     * @param Profile $profile
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    protected function inProfile(Profile $profile, Closure $callback): mixed
    {
        return CurrentProfile::run($profile, $callback);
    }

    /**
     * Cache NBU rates, such as `['USD' => ['2026-10-05' => '42.2297']]`.
     *
     * @param array<string, array<string, string>> $rates
     *
     * @return void
     */
    protected function cacheRates(array $rates): void
    {
        foreach ($rates as $currency => $byDate) {
            foreach ($byDate as $date => $rate) {
                ExchangeRate::query()->create([
                    'currency' => $currency,
                    'rate_date' => $date,
                    'rate' => $rate,
                    'fetched_at' => now(),
                ]);
            }
        }
    }

    /**
     * Mark the declaration of the profile's quarter accepted, which locks the quarter.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return TaxPeriod
     */
    protected function declareQuarter(Profile $profile, int $year, int $quarter): TaxPeriod
    {
        return $this->inProfile($profile, function () use ($profile, $year, $quarter) {
            $period = app(DeadlineScheduler::class)->ensure($profile, $year, $quarter);
            $period->forceFill(['declaration_status' => DeclarationStatus::Accepted, 'locked_at' => now()])->save();

            return $period;
        });
    }

    /**
     * Get the API path of the profile's records, such as `/api/profiles/1/invoices`.
     *
     * @param Profile $profile
     * @param string $path
     *
     * @return string
     */
    protected function profilePath(Profile $profile, string $path): string
    {
        return "/api/profiles/{$profile->id}/" . ltrim($path, '/');
    }

    /**
     * Create a client of the profile with a USD contract at 45.00 an hour, payable in 14 days.
     *
     * @param Profile $profile
     *
     * @return array{0: Client, 1: Contract}
     */
    protected function createClientWithContract(Profile $profile): array
    {
        return $this->inProfile($profile, function () use ($profile) {
            $client = Client::factory()->create(['profile_id' => $profile->id, 'name' => 'Brightline Labs LLC']);
            $contract = Contract::factory()->create(['client_id' => $client->id, 'profile_id' => $profile->id]);
            $contract->rates()->create(['effective_from' => '2023-04-13', 'hourly_rate' => '45.00']);

            return [$client, $contract];
        });
    }

    /**
     * Create an account of the profile.
     *
     * @param Profile $profile
     * @param array $attributes
     *
     * @return Account
     */
    protected function createAccount(Profile $profile, array $attributes = []): Account
    {
        return $this->inProfile($profile, fn () => Account::factory()->create(['profile_id' => $profile->id, ...$attributes]));
    }

    /**
     * Create a frozen invoice of the profile: 86.50 hours at 45.00, 3 892.50 USD for September 2026, paid to the
     * account.
     *
     * @param Profile $profile
     * @param Account $account
     * @param array $data Data of the draft, which replaces the defaults.
     *
     * @return Invoice
     */
    protected function createFrozenInvoice(Profile $profile, Account $account, array $data = []): Invoice
    {
        [$client, $contract] = $this->createClientWithContract($profile);

        return $this->inProfile($profile, function () use ($profile, $account, $client, $contract, $data) {
            $draft = app(InvoiceWriter::class)->create($profile, [
                'client_id' => $client->id,
                'contract_id' => $contract->id,
                'receiving_account_id' => $account->id,
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'items' => [['quantity' => '86.50']],
                ...$data,
            ]);

            return app(InvoiceFreezer::class)->freeze($profile, $draft);
        });
    }

    /**
     * Record a receipt of the invoice through PaymentRecorder.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param Account $account
     * @param array $data Data of the receipt, which replaces the defaults.
     *
     * @return Payment
     */
    protected function recordReceipt(Profile $profile, Invoice $invoice, Account $account, array $data = []): Payment
    {
        return $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            'kind' => 'invoice',
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'received_to_account_id' => $account->id,
            'received_at' => '2026-10-05',
            ...$data,
        ]));
    }
}
