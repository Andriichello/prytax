<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Account;
use PryTax\Models\AccountRequisiteSet;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PaymentSectionsTest.
 *
 * The «Payment details (…)» sections of an invoice (invoice.md §4), through the invoices' API: a new invoice takes
 * those of the client's last invoice to the account, while their sets are active, else the account's default set; a
 * draft moved to another account takes that one's; a set of another account, switched off or twice, and parentheses
 * without a name are refused (422); two sections or more print their parentheses, frozen as they print.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class PaymentSectionsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a new invoice of the client takes the sections of its last invoice to the account, in their order and
     * with their parentheses, and another client's the account's default set without them.
     *
     * @return void
     */
    public function testStartsWithTheSectionsOfTheClientsLastInvoiceToTheAccount(): void
    {
        [$profile, , $sepa, $swift] = $this->accountWithSepaAndSwift();
        [$client, $contract] = $this->createClientWithContract($profile);
        [$other, $otherContract] = $this->createClientWithContract($profile, ['name' => 'Kestrel Ltd', 'abbreviation' => 'KS']);
        $this->postDraft($profile, $client, $contract, ['payment_sections' => [
            ['requisite_set_id' => $swift->id],
            ['requisite_set_id' => $sepa->id, 'title' => 'Europe'],
        ]])->assertCreated();

        $next = $this->postDraft($profile, $client, $contract);
        $another = $this->postDraft($profile, $other, $otherContract);

        $this->assertSame([[$swift->id, null, true], [$sepa->id, 'Europe', true]], $this->sectionsOf($profile, $next->json('data.id')));
        $this->assertSame([[$sepa->id, null, false]], $this->sectionsOf($profile, $another->json('data.id')));
    }

    /**
     * Test that a new invoice prints the account's default set, when the sets of the client's last invoice are
     * switched off since.
     *
     * @return void
     */
    public function testFallsBackToTheDefaultSetWhenTheLastInvoicesSetsAreOff(): void
    {
        [$profile, , $sepa, $swift] = $this->accountWithSepaAndSwift();
        [$client, $contract] = $this->createClientWithContract($profile);
        $this->postDraft($profile, $client, $contract, ['payment_sections' => [['requisite_set_id' => $swift->id]]])->assertCreated();
        $this->inProfile($profile, fn () => $swift->update(['is_active' => false]));

        $next = $this->postDraft($profile, $client, $contract);

        $this->assertSame([[$sepa->id, null, false]], $this->sectionsOf($profile, $next->json('data.id')));
    }

    /**
     * Test that a draft moved to another account prints that account's default set.
     *
     * @return void
     */
    public function testMovesTheSectionsWithTheReceivingAccount(): void
    {
        [$profile, , , $swift] = $this->accountWithSepaAndSwift();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $payoneerSet = $this->createRequisiteSet($payoneer);
        [$client, $contract] = $this->createClientWithContract($profile);
        $draft = $this->postDraft($profile, $client, $contract, ['payment_sections' => [['requisite_set_id' => $swift->id]]])->json('data');

        $response = $this->withHeader('If-Unmodified-Since', $draft['updated_at'])
            ->patchJson($this->profilePath($profile, "invoices/{$draft['id']}"), ['receiving_account_id' => $payoneer->id]);

        $response->assertOk();
        $this->assertSame([[$payoneerSet->id, null, false]], $this->sectionsOf($profile, $draft['id']));
    }

    /**
     * Test that sections, which the invoice can't print, are refused (422), and no draft is saved.
     *
     * @param string $case
     * @param string $key
     * @param string $message
     *
     * @return void
     */
    #[DataProvider('wrongSections')]
    public function testReturns422ForSectionsItCantPrint(string $case, string $key, string $message): void
    {
        [$profile, $account, $sepa, $swift] = $this->accountWithSepaAndSwift();
        [$client, $contract] = $this->createClientWithContract($profile);
        $foreign = $this->createRequisiteSet($this->createAccount($profile, ['name' => 'Payoneer USD']));
        $unnamed = $this->createRequisiteSet($account, ['is_default' => false]);
        $off = $this->createRequisiteSet($account, state: 'inactive');
        $sections = match ($case) {
            'another account\'s' => [['requisite_set_id' => $foreign->id]],
            'switched off' => [['requisite_set_id' => $off->id]],
            'twice' => [['requisite_set_id' => $sepa->id], ['requisite_set_id' => $sepa->id]],
            'two, one without a name' => [['requisite_set_id' => $swift->id], ['requisite_set_id' => $unnamed->id]],
            'one in parentheses without a name' => [['requisite_set_id' => $unnamed->id, 'show_title' => true]],
        };

        $response = $this->postDraft($profile, $client, $contract, ['payment_sections' => $sections]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Get the sections, which an invoice can't print, with the key and the message of the refusal.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function wrongSections(): array
    {
        return [
            'another account\'s' => ['another account\'s', 'payment_sections.0.requisite_set_id', 'The requisites aren\'t the receiving account\'s.'],
            'switched off' => ['switched off', 'payment_sections.0.requisite_set_id', 'The requisites are switched off.'],
            'twice' => ['twice', 'payment_sections.1.requisite_set_id', 'The invoice prints these requisites already.'],
            'two, one without a name' => ['two, one without a name', 'payment_sections.1.title', 'Of several payment details, each prints its name in parentheses: give it one.'],
            'one in parentheses without a name' => ['one in parentheses without a name', 'payment_sections.0.title', 'Give the payment details a name to print in parentheses.'],
        ];
    }

    /**
     * Test that freezing keeps each section as it prints: its parentheses, its account and its requisites.
     *
     * @return void
     */
    public function testFreezesEachSectionWithItsParentheses(): void
    {
        Storage::fake('local');
        [$profile, , $sepa, $swift] = $this->accountWithSepaAndSwift();
        [$client, $contract] = $this->createClientWithContract($profile);
        $id = $this->postDraft($profile, $client, $contract, ['payment_sections' => [
            ['requisite_set_id' => $swift->id],
            ['requisite_set_id' => $sepa->id, 'title' => 'Europe'],
        ]])->json('data.id');

        $response = $this->postJson($this->profilePath($profile, "invoices/$id/freeze?append=snapshot"));

        $response->assertOk()
            ->assertJsonPath('data.snapshot.payment_sections.0.title', 'SWIFT')
            ->assertJsonPath('data.snapshot.payment_sections.0.swift', 'UNJSUAUKXXX')
            ->assertJsonPath('data.snapshot.payment_sections.0.account.name', 'Monobank ФОП EUR')
            ->assertJsonPath('data.snapshot.payment_sections.1.title', 'Europe')
            ->assertJsonPath('data.snapshot.payment_sections.1.bic', 'CLJUGB21');
    }

    /**
     * Create a ФОП, whose owner acts, with an account and its two sets: SEPA, the default, and SWIFT.
     *
     * @return array{0: Profile, 1: Account, 2: AccountRequisiteSet, 3: AccountRequisiteSet}
     */
    private function accountWithSepaAndSwift(): array
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile, ['name' => 'Monobank ФОП EUR']);

        return [
            $profile,
            $account,
            $this->createRequisiteSet($account, state: 'sepa'),
            $this->createRequisiteSet($account, ['is_default' => false], 'swift'),
        ];
    }

    /**
     * Create a draft of the client for October 2026 through the API.
     *
     * @param Profile $profile
     * @param Client $client
     * @param Contract $contract
     * @param array $data
     *
     * @return TestResponse
     */
    private function postDraft(Profile $profile, Client $client, Contract $contract, array $data = []): TestResponse
    {
        return $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'items' => [['quantity' => '10.00']],
            ...$data,
        ]);
    }

    /**
     * Get the invoice's sections, as their set, their parentheses' text and whether they print them.
     *
     * @param Profile $profile
     * @param int $id
     *
     * @return list<array{int, string|null, bool}>
     */
    private function sectionsOf(Profile $profile, int $id): array
    {
        $sections = $this->getJson($this->profilePath($profile, "invoices/$id?include=paymentSections"))->assertOk()->json('data.paymentSections');

        return array_map(fn (array $section) => [$section['requisite_set_id'], $section['title'], $section['show_title']], $sections);
    }
}
