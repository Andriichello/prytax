<?php

namespace Tests\Feature\Mcp;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PryTax\Mcp\Servers\PryTaxServer;
use PryTax\Mcp\Tools\GetAccounting;
use PryTax\Mcp\Tools\GetBackupStatus;
use PryTax\Mcp\Tools\GetProfile;
use PryTax\Mcp\Tools\GetTaxLedger;
use PryTax\Mcp\Tools\ListInvoices;
use PryTax\Mcp\Tools\ListProfiles;
use PryTax\Mcp\Tools\ListReceipts;
use PryTax\Models\Integration;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PryTaxServerTest.
 *
 * PryTax's MCP server for Claude (builds.md §7): its tools read only the ФОП, which the organization's integration
 * allows, named by id, by name or alone; as the acting user, whose role decides; nothing, while it's off; and a read,
 * which writes as it goes, keeps nothing, while the call is recorded.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Mcp
 */
class PryTaxServerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the ФОП listed are the organization's, which the integration allows.
     *
     * @return void
     */
    public function testListsOnlyTheAllowedProfilesOfTheOrganization(): void
    {
        $organization = Organization::factory()->create();
        [$first, $second] = [$this->createProfile($organization), $this->createProfile($organization)];
        $this->createProfile($organization);
        $this->createProfile();
        $owner = $this->claudeOf($organization, ['profile_ids' => [$first->id, $second->id]]);

        $response = PryTaxServer::actingAs($owner)->tool(ListProfiles::class);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json
            ->has('profiles', 2)
            ->where('profiles', fn ($profiles) => collect($profiles)->pluck('id')->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all()));
    }

    /**
     * Test that a ФОП is named by part of its name (Ukrainian or English), by its id, or left out when it's the only
     * one; and that another organization's, or an ambiguous name, is refused with the candidates.
     *
     * @return void
     */
    public function testNamesTheProfileByNameOrIdOrAlone(): void
    {
        $organization = Organization::factory()->create();
        $kovalenko = $this->createProfile($organization, ['full_name' => 'Коваленко Олена Петрівна', 'full_name_en' => 'Olena Kovalenko']);
        $this->createProfile($organization, ['full_name' => 'Коваль Ігор Петрович']);
        $stranger = $this->createProfile();
        $owner = $this->claudeOf($organization);

        PryTaxServer::actingAs($owner)->tool(GetProfile::class, ['profile' => 'оленА'])->assertOk()->assertSee('Коваленко Олена Петрівна');
        PryTaxServer::actingAs($owner)->tool(GetProfile::class, ['profile' => 'olena'])->assertOk()->assertSee('Коваленко Олена Петрівна');
        PryTaxServer::actingAs($owner)->tool(GetProfile::class, ['profile' => (string) $kovalenko->id])->assertOk()->assertSee('Коваленко Олена Петрівна');
        PryTaxServer::actingAs($owner)->tool(GetProfile::class, ['profile' => 'Ковал'])->assertHasErrors(['Several ФОП match «Ковал»']);
        PryTaxServer::actingAs($owner)->tool(GetProfile::class, ['profile' => (string) $stranger->id])->assertHasErrors(["There's no ФОП #$stranger->id here"]);
        PryTaxServer::actingAs($owner)->tool(GetProfile::class)->assertHasErrors(['Name the ФОП']);
    }

    /**
     * Test that the only ФОП serves without being named.
     *
     * @return void
     */
    public function testReadsTheOnlyProfileWithoutItsName(): void
    {
        $profile = $this->createProfile(attributes: ['full_name' => 'Бондар Ірина Василівна']);
        $owner = $this->claudeOf($profile->organization);

        PryTaxServer::actingAs($owner)->tool(GetProfile::class)->assertOk()->assertSee('Бондар Ірина Василівна');
    }

    /**
     * Test that nothing is read while the integration is off, or before it's ever turned on.
     *
     * @return void
     */
    public function testReadsNothingWhileItsOff(): void
    {
        $profile = $this->createProfile();
        $owner = $this->userOf($profile->organization);

        PryTaxServer::actingAs($owner)->tool(ListProfiles::class)->assertHasErrors(['PRYTAX_MCP_KEY isn\'t set']);

        Integration::factory()->enabled($owner)->create()->forceFill(['enabled_at' => null, 'disabled_at' => now()])->save();

        PryTaxServer::actingAs($owner)->tool(ListProfiles::class)->assertHasErrors(['Claude is turned off']);
    }

    /**
     * Test that the acting user's role decides: a viewer reads invoices, not the backups.
     *
     * @return void
     */
    public function testReadsWithTheActingUsersRole(): void
    {
        $profile = $this->createProfile();
        $viewer = $this->userOf($profile->organization, 'viewer');
        $owner = $this->claudeOf($profile->organization, ['acting_user_id' => $viewer->id]);

        PryTaxServer::actingAs($owner)->tool(ListInvoices::class)->assertOk();
        PryTaxServer::actingAs($owner)->tool(GetBackupStatus::class)->assertHasErrors(["Claude reads PryTax as $viewer->name, whose role doesn't allow this"]);
    }

    /**
     * Test that the invoices are filtered by status, as Інвойси filters them.
     *
     * @return void
     */
    public function testFiltersTheInvoicesByStatus(): void
    {
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);
        $ready = $this->createFrozenInvoice($profile, $this->createAccount($profile));

        $all = PryTaxServer::actingAs($owner)->tool(ListInvoices::class);
        $drafts = PryTaxServer::actingAs($owner)->tool(ListInvoices::class, ['status' => ['draft']]);

        $all->assertOk()->assertStructuredContent(fn ($json) => $json->where('found', 1)->where('invoices.0.id', $ready->id)->etc());
        $drafts->assertOk()->assertStructuredContent(fn ($json) => $json->where('found', 0)->etc());
    }

    /**
     * Test that the quarter's accounting has a receipt's income.
     *
     * @return void
     */
    public function testReadsTheQuartersIncome(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $this->createAccount($profile)->id, 'received_at' => '2026-07-05',
        ]));

        $response = PryTaxServer::actingAs($owner)->tool(GetAccounting::class, ['year' => 2026, 'quarter' => 3]);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json->where('summary.quarter_income', $payment->uah_amount)->etc());
    }

    /**
     * Test that a receipt's invoice says what it was paid, as its other reads do: worked out in the ФОП, before the
     * answer leaves it.
     *
     * @return void
     */
    public function testReceiptsShowWhatTheirInvoicesWerePaid(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $this->createAccount($profile)->id, 'received_at' => '2026-10-05',
        ]));

        $response = PryTaxServer::actingAs($owner)->tool(ListReceipts::class, ['year' => 2026]);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('found', 1)
            ->where('receipts.0.invoice.paid_amount', $payment->amount)
            ->etc());
    }

    /**
     * Test that a month outside the quarter given is refused, not answered with nothing.
     *
     * @return void
     */
    public function testRefusesAMonthOutsideTheQuarter(): void
    {
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);

        PryTaxServer::actingAs($owner)->tool(ListReceipts::class, ['year' => 2026, 'quarter' => 4, 'month' => 3])
            ->assertHasErrors(["Month 3 isn't in quarter 4"]);
        PryTaxServer::actingAs($owner)->tool(ListReceipts::class, ['year' => 2026, 'quarter' => 4, 'month' => 11])->assertOk();
    }

    /**
     * Test that a read, which ensures the year's tax periods and ЄСВ months as it goes, keeps none of them, nor their
     * audit, while the call itself is recorded.
     *
     * @return void
     */
    public function testKeepsNothingTheReadWrites(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);
        $counts = fn () => collect(['tax_periods', 'esv_obligations', 'audit_logs'])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $before = $counts();

        $response = PryTaxServer::actingAs($owner)->tool(GetTaxLedger::class, ['year' => 2026]);

        $response->assertOk();
        $this->assertSame($before, $counts());
        $this->assertSame('get-tax-ledger', Integration::of($owner->organization_id, Integration::CLAUDE)->last_tool);
    }

    /**
     * Turn Claude on for the organization, by a new owner, with the settings, and get the owner.
     *
     * @param Organization $organization
     * @param array<string, mixed> $settings
     *
     * @return User
     */
    private function claudeOf(Organization $organization, array $settings = []): User
    {
        $owner = $this->userOf($organization);
        Integration::factory()->enabled($owner)->create($settings);

        return $owner;
    }
}
