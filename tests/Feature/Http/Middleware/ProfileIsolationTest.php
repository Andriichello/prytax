<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Alert;
use PryTax\Models\ChargeType;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\EsvObligation;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Project;
use PryTax\Models\Refund;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPayment;
use PryTax\Models\TaxPaymentRequisite;
use PryTax\Models\TaxPeriod;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ProfileIsolationTest.
 *
 * Profiles are tenants (design.md §0): a record of another profile of the same organization is a plain 404 under
 * this profile's path, for every resource and every nested one, so a profile never learns of another's records.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Middleware
 */
class ProfileIsolationTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * The resources, by the path of a record of the other profile, which each builds.
     *
     * @return array<string, array{0: string}>
     */
    public static function resources(): array
    {
        return collect([
            'accounts/{account}', 'accounts/{account}/statement', 'accounts/{account}/movements/{movement}',
            'accounts/{account}/opening-balances/{opening}', 'clients/{client}', 'clients/{client}/stats',
            'clients/{client}/contracts/{contract}', 'clients/{client}/projects/{project}',
            'clients/{client}/charge-types/{charge}', 'invoices/{invoice}', 'invoices/{invoice}/preview',
            'invoices/{invoice}/files', 'payments/{payment}', 'payments/{payment}/flow',
            'payments/{payment}/refunds/{refund}', 'transfers/{transfer}', 'tax-payments/{tax}',
            'tax-periods/{period}', 'tax-opening-balances/{taxOpening}', 'tax-payment-requisites/{requisite}',
            'esv-obligations/{esv}', 'alerts/{alert}',
        ])->mapWithKeys(fn (string $path) => [$path => [$path]])->all();
    }

    /**
     * Create a record of each kind in the profile, by the names of the paths.
     *
     * @param Profile $profile
     *
     * @return array<string, int>
     */
    private function recordsOf(Profile $profile): array
    {
        return $this->inProfile($profile, function () use ($profile) {
            $of = ['profile_id' => $profile->id];
            $account = $this->createAccount($profile);
            $contract = Contract::factory()->create(['client_id' => Client::factory()->create($of)->id]);
            $payment = Payment::factory()->create([...$of, 'received_to_account_id' => $account->id]);

            return [
                'account' => $account->id,
                'movement' => AccountMovement::factory()->create([...$of, 'account_id' => $account->id])->id,
                'opening' => AccountOpeningBalance::factory()->create([...$of, 'account_id' => $account->id])->id,
                'client' => $contract->client_id,
                'contract' => $contract->id,
                'project' => Project::factory()->create(['client_id' => $contract->client_id])->id,
                'charge' => ChargeType::factory()->create(['client_id' => $contract->client_id])->id,
                'invoice' => Invoice::factory()->create($of)->id,
                'payment' => $payment->id,
                'refund' => Refund::factory()->create([...$of, 'payment_id' => $payment->id])->id,
                'transfer' => AccountTransfer::factory()->create($of)->id,
                'tax' => TaxPayment::factory()->create($of)->id,
                'period' => TaxPeriod::factory()->create($of)->id,
                'taxOpening' => TaxOpeningBalance::factory()->create($of)->id,
                'requisite' => TaxPaymentRequisite::factory()->create($of)->id,
                'esv' => EsvObligation::factory()->create($of)->id,
                'alert' => Alert::factory()->create($of)->id,
            ];
        });
    }

    /**
     * Test that the record of another profile of the organization isn't found (404) under this profile's path.
     *
     * @param string $path
     *
     * @return void
     */
    #[DataProvider('resources')]
    public function testReturns404ForARecordOfAnotherProfile(string $path): void
    {
        $profile = $this->createProfile();
        $other = $this->createProfile($profile->organization);
        $this->actAs($profile);
        $records = $this->recordsOf($other);
        $url = preg_replace_callback('/\{(\w+)}/', fn (array $match) => $records[$match[1]], $path);

        $this->getJson($this->profilePath($profile, $url))->assertNotFound();
        $this->getJson($this->profilePath($other, $url))->assertSuccessful();
    }
}
