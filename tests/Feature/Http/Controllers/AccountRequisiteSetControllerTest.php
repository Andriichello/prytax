<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Account;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountRequisiteSetControllerTest.
 *
 * Sets of an account's requisites (invoice.md §4): one code for SWIFT and BIC, normalized, and the labels its fields
 * print under, blank ones dropped, another key refused (422); the first set the default, made
 * the default another one takes it over, and the default neither switched off nor deleted while there are others; the
 * order of a set's requisites in invoices and which of them print, refused with 422, when an entry names neither a
 * field nor a label, both or an unknown field.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class AccountRequisiteSetControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an account's first set is its default, its IBAN and code upper case without spaces, its labels
     * trimmed and blank ones dropped, and that another set made the default takes it over.
     *
     * @return void
     */
    public function testMakesTheFirstSetTheDefaultAndMovesTheDefaultToAnother(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $account = $this->createAccount($profile, ['currency' => 'EUR']);
        $path = $this->profilePath($profile, "accounts/{$account->id}/requisite-sets");

        $sepa = $this->postJson($path, [
            'name' => 'SEPA', 'iban' => 'gb12 3456 7890 1234 5678 90', 'swift' => 'cljugb21',
            'labels' => ['holder_name' => ' Beneficiary name ', 'swift' => 'BIC code', 'bank_name' => ' '],
        ]);
        $swift = $this->postJson($path, ['name' => 'SWIFT', 'swift' => 'unjs ua uk xxx', 'is_default' => true]);

        $sepa->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.iban', 'GB12345678901234567890')
            ->assertJsonPath('data.swift', 'CLJUGB21')
            ->assertJsonPath('data.labels', ['holder_name' => 'Beneficiary name', 'swift' => 'BIC code']);
        $swift->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.swift', 'UNJSUAUKXXX');
        $this->getJson("$path?sort=id")->assertOk()
            ->assertJsonPath('data.0.name', 'SEPA')
            ->assertJsonPath('data.0.is_default', false)
            ->assertJsonPath('data.1.is_default', true);
    }

    /**
     * Test that the default set is neither switched off, nor made no default, nor deleted, while the account has
     * another set (422), and stays as it was.
     *
     * @param array|null $change
     * @param string $key
     * @param string $message
     *
     * @return void
     */
    #[DataProvider('changesOfTheDefault')]
    public function testReturns422ForLosingTheDefault(?array $change, string $key, string $message): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $default = $this->createRequisiteSet($account);
        $this->createRequisiteSet($account, state: 'other');
        $path = $this->profilePath($profile, "accounts/{$account->id}/requisite-sets/{$default->id}");

        $response = $change === null ? $this->deleteJson($path) : $this->patchJson($path, $change);

        $response->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);
        $this->assertTrue($default->refresh()->is_default);
        $this->assertTrue($default->is_active);
    }

    /**
     * Get the changes, which would leave the account without its default, with the key and the message of the
     * refusal (null deletes the set).
     *
     * @return array<string, array{array|null, string, string}>
     */
    public static function changesOfTheDefault(): array
    {
        return [
            'switched off' => [['is_active' => false], 'is_active', 'The default set can\'t be switched off: make another set the default first.'],
            'no default' => [['is_default' => false], 'is_default', 'An account\'s sets have a default: make another set the default instead.'],
            'deleted' => [null, 'id', 'The default set can\'t be deleted: make another set the default first.'],
        ];
    }

    /**
     * Test that a set, which only a deleted draft printed, is deleted with that draft's section, the account's only
     * set and its default too.
     *
     * @return void
     */
    public function testDeletesASetWhichOnlyADeletedDraftPrinted(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $set = $this->createRequisiteSet($account);
        $draft = $this->draftPaidInto($profile, $account);
        $this->deleteJson($this->profilePath($profile, "invoices/$draft"))->assertOk();

        $response = $this->deleteJson($this->profilePath($profile, "accounts/{$account->id}/requisite-sets/{$set->id}"));

        $response->assertOk();
        $this->assertDatabaseMissing('account_requisite_sets', ['id' => $set->id]);
        $this->assertDatabaseMissing('invoice_payment_sections', ['invoice_id' => $draft]);
    }

    /**
     * Test that a set, which an invoice prints, isn't deleted (422), and says to switch it off instead.
     *
     * @return void
     */
    public function testReturns422ForDeletingASetWhichAnInvoicePrints(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $this->createRequisiteSet($account);
        $swift = $this->createRequisiteSet($account, ['is_default' => false, 'name' => 'SWIFT']);
        $this->draftPaidInto($profile, $account, ['payment_sections' => [['requisite_set_id' => $swift->id]]]);

        $response = $this->deleteJson($this->profilePath($profile, "accounts/{$account->id}/requisite-sets/{$swift->id}"));

        $response->assertUnprocessable()->assertJsonValidationErrors(['id' => 'Invoices print these requisites, so they can\'t be deleted: switch them off instead.']);
        $this->assertModelExists($swift);
    }

    /**
     * Test that an accountant saves the order of a set's requisites in invoices, and which of them print.
     *
     * @return void
     */
    public function testSavesTheOrderOfTheRequisitesInInvoices(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $account = $this->createAccount($profile);
        $set = $this->createRequisiteSet($account, ['details' => [['label' => 'Routing (ABA)', 'value' => '022200101']]]);
        $order = [
            ['label' => 'Routing (ABA)', 'shown' => true],
            ['field' => 'swift', 'shown' => false],
            ['field' => 'holder_name', 'shown' => true],
        ];

        $response = $this->patchJson($this->profilePath($profile, "accounts/{$account->id}/requisite-sets/{$set->id}"), ['invoice_requisites' => $order]);

        $response->assertOk()->assertJsonPath('data.invoice_requisites', $order);
        $this->assertSame($order, $set->refresh()->invoice_requisites);
    }

    /**
     * Test that an entry of the order, which names neither a field nor a label, both, an unknown field, or leaves out
     * whether it prints, is refused (422), and nothing is saved.
     *
     * @param array $entry
     * @param string $key
     * @param string $message
     *
     * @return void
     */
    #[DataProvider('wrongEntries')]
    public function testReturns422ForAWrongEntryOfTheOrder(array $entry, string $key, string $message): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $set = $this->createRequisiteSet($account);

        $response = $this->patchJson($this->profilePath($profile, "accounts/{$account->id}/requisite-sets/{$set->id}"), ['invoice_requisites' => [$entry]]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);
        $this->assertNull($set->refresh()->invoice_requisites);
    }

    /**
     * Create a draft of a new client for October 2026, paid into the account, through the API, and get its id.
     *
     * @param Profile $profile
     * @param Account $account
     * @param array $data
     *
     * @return int
     */
    private function draftPaidInto(Profile $profile, Account $account, array $data = []): int
    {
        [$client, $contract] = $this->createClientWithContract($profile);

        return $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id, 'receiving_account_id' => $account->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'items' => [['quantity' => '10.00']], ...$data,
        ])->assertCreated()->json('data.id');
    }

    /**
     * Get the wrong entries, with the key and the message of the refusal.
     *
     * @return array<string, array{array, string, string}>
     */
    public static function wrongEntries(): array
    {
        return [
            'neither' => [['shown' => true], 'invoice_requisites.0.field', 'The invoice_requisites.0.field field is required when invoice_requisites.0.label is not present.'],
            'both' => [['field' => 'iban', 'label' => 'IBAN', 'shown' => true], 'invoice_requisites.0.field', 'The invoice_requisites.0.field field prohibits invoice_requisites.0.label from being present.'],
            'an unknown field' => [['field' => 'name', 'shown' => true], 'invoice_requisites.0.field', 'The selected invoice_requisites.0.field is invalid.'],
            'without shown' => [['field' => 'iban'], 'invoice_requisites.0.shown', 'The invoice_requisites.0.shown field is required.'],
        ];
    }

    /**
     * Test that a label of a field the sets don't have is refused (422), and nothing is saved.
     *
     * @return void
     */
    public function testReturns422ForALabelOfAnUnknownField(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $set = $this->createRequisiteSet($account);

        $response = $this->patchJson($this->profilePath($profile, "accounts/{$account->id}/requisite-sets/{$set->id}"), ['labels' => ['bic' => 'BIC']]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['labels' => 'The labels field must be an array.']);
        $this->assertNull($set->refresh()->labels);
    }
}
