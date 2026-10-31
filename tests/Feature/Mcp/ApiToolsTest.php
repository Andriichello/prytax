<?php

namespace Tests\Feature\Mcp;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PryTax\Mcp\Servers\PryTaxServer;
use PryTax\Mcp\Tools\ApiEndpoints;
use PryTax\Mcp\Tools\ApiRead;
use PryTax\Mcp\Tools\ApiWrite;
use PryTax\Mcp\Tools\ReadSpreadsheet;
use PryTax\Models\Client;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Services\Invoices\InvoiceWriter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;
use ZipArchive;

/**
 * Class ApiToolsTest.
 *
 * Claude's way to everything its acting user may do (builds.md §7): any read of the API while it's on; changes only
 * once an owner allowed them, as that user's role permits, with local files as uploads; never its own integration,
 * nor another ФОП than those allowed; the endpoints with their fields; and a spreadsheet's rows, from a CSV or an
 * XLSX.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Mcp
 */
class ApiToolsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * The files the test made, which it removes.
     *
     * @var list<string>
     */
    private array $files = [];

    /**
     * Remove the test's files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));

        parent::tearDown();
    }

    /**
     * Test that Claude reads through the API while it's on, but changes nothing, nor sees api-write, until changes are
     * allowed.
     *
     * @return void
     */
    public function testReadsThroughTheApiButChangesNothingUntilAllowed(): void
    {
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);
        $this->createClientWithContract($profile);

        PryTaxServer::actingAs($owner)->tool(ApiRead::class, ['path' => "profiles/$profile->id/clients"])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 200)->has('body.data', 1)->etc());
        PryTaxServer::actingAs($owner)->tools()->assertNotRegistered(ApiWrite::class);
        PryTaxServer::actingAs($owner)->tool(ApiWrite::class, ['method' => 'POST', 'path' => "profiles/$profile->id/clients", 'body' => ['name' => 'Nordwave GmbH']])
            ->assertNotRegistered();

        $this->assertSame(1, Client::query()->withoutGlobalScopes()->count());
    }

    /**
     * Test that one list gives every tool, api-write among them while changes are allowed: clients don't all page.
     *
     * @return void
     */
    public function testListsEveryToolAtOnce(): void
    {
        $owner = $this->claudeOf($this->createProfile()->organization, writes: true);

        PryTaxServer::actingAs($owner)->tools()->assertRegistered([ApiEndpoints::class, ApiRead::class, ApiWrite::class, ReadSpreadsheet::class]);
    }

    /**
     * Test that, with changes allowed, Claude creates what its user may, through the API, as that user.
     *
     * @return void
     */
    public function testChangesDataAsItsUserOnceAllowed(): void
    {
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization, writes: true);

        $response = PryTaxServer::actingAs($owner)->tool(ApiWrite::class, [
            'method' => 'POST',
            'path' => "/api/profiles/$profile->id/clients",
            'body' => ['name' => 'Nordwave GmbH', 'abbreviation' => 'NW', 'currency' => 'EUR'],
        ]);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 201)->where('body.data.name', 'Nordwave GmbH')->etc());
        $this->assertTrue(Client::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('name', 'Nordwave GmbH')->exists());
        $this->assertSame("api-write POST /api/profiles/$profile->id/clients", Integration::of($owner->organization_id, Integration::CLAUDE)->last_tool);
    }

    /**
     * Test that the acting user's role decides what Claude may change: a viewer's changes are the API's 403s.
     *
     * @return void
     */
    public function testChangesOnlyWhatItsUsersRolePermits(): void
    {
        $profile = $this->createProfile();
        $viewer = $this->userOf($profile->organization, 'viewer');
        $owner = $this->claudeOf($profile->organization, ['acting_user_id' => $viewer->id], writes: true);

        PryTaxServer::actingAs($owner)->tool(ApiWrite::class, ['method' => 'POST', 'path' => "profiles/$profile->id/clients", 'body' => ['name' => 'Nordwave GmbH']])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 403)->etc());

        $this->assertSame(0, Client::query()->withoutGlobalScopes()->count());
    }

    /**
     * Test that Claude never reaches its own integration, nor another ФОП than those allowed.
     *
     * @return void
     */
    public function testNeverReachesItsIntegrationNorOtherProfiles(): void
    {
        $organization = Organization::factory()->create();
        [$allowed, $other] = [$this->createProfile($organization), $this->createProfile($organization)];
        $owner = $this->claudeOf($organization, ['profile_ids' => [$allowed->id]], writes: true);

        PryTaxServer::actingAs($owner)->tool(ApiWrite::class, ['method' => 'PUT', 'path' => 'organization/integrations/claude', 'body' => ['acting_user_id' => null]])
            ->assertHasErrors(["Claude doesn't reach /api/organization/integrations/claude"]);
        PryTaxServer::actingAs($owner)->tool(ApiRead::class, ['path' => "profiles/$other->id/invoices"])
            ->assertHasErrors(['Claude sees some ФОП only']);
        PryTaxServer::actingAs($owner)->tool(ApiRead::class, ['path' => "profiles/$allowed->id/invoices"])->assertOk();
    }

    /**
     * Test that a local file goes as an upload: an invoice's PDF, on its draft.
     *
     * @return void
     */
    public function testUploadsALocalFile(): void
    {
        Storage::fake((string) config('api-core.files.disk'));
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization, writes: true);
        $invoice = $this->createDraft($profile);
        $pdf = $this->files[] = sys_get_temp_dir() . '/prytax-claude-' . Str::random(8) . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        $response = PryTaxServer::actingAs($owner)->tool(ApiWrite::class, [
            'method' => 'POST',
            'path' => "profiles/$profile->id/invoices/$invoice->id/files",
            'body' => ['primary' => '1'],
            'files' => ['file' => $pdf],
        ]);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 201)->etc());
        $this->assertSame(1, $invoice->files()->count());
    }

    /**
     * Test that an error comes without where it was thrown, even in debug mode: its message, not the installation's
     * code and folders, nor a trace, which would fill Claude's context.
     *
     * @return void
     */
    public function testAnswersAnErrorWithoutItsTrace(): void
    {
        config(['app.debug' => true]);
        $profile = $this->createProfile();
        $owner = $this->claudeOf($profile->organization);

        $response = PryTaxServer::actingAs($owner)->tool(ApiRead::class, ['path' => "profiles/$profile->id/invoices/999999"]);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('status', 404)
            ->has('body.message')
            ->missing('body.exception')
            ->missing('body.file')
            ->missing('body.line')
            ->missing('body.trace')
            ->etc());
    }

    /**
     * Test that the endpoints are listed with their fields while changes are allowed, and only reads otherwise.
     *
     * @return void
     */
    public function testListsTheEndpointsWithTheirFields(): void
    {
        $profile = $this->createProfile();
        $reader = $this->claudeOf($profile->organization);
        $writer = $this->claudeOf($this->createProfile()->organization, writes: true);

        $reads = PryTaxServer::actingAs($reader)->tool(ApiEndpoints::class);
        $writes = PryTaxServer::actingAs($writer)->tool(ApiEndpoints::class, ['search' => 'clients']);

        $reads->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('writes', false)
            ->where('endpoints', fn ($endpoints) => collect($endpoints)->every(fn ($endpoint) => in_array('GET', $endpoint['methods'], true))));
        $writes->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('writes', true)
            ->where('endpoints', fn ($endpoints) => str_contains((string) (collect($endpoints)->first(fn ($endpoint) => $endpoint['path'] === 'profiles/{profile}/clients' && in_array('POST', $endpoint['methods'], true))['fields']['name'] ?? ''), 'required')));
        PryTaxServer::actingAs($writer)->tool(ApiEndpoints::class, ['search' => 'integrations'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('endpoints', [])->etc());
    }

    /**
     * Test that a CSV's rows come keyed by its headers, whatever its delimiter, without its byte order mark.
     *
     * @return void
     */
    public function testReadsACsvsRowsByItsHeaders(): void
    {
        $owner = $this->claudeOf(Organization::factory()->create());
        $csv = $this->files[] = sys_get_temp_dir() . '/prytax-claude-' . Str::random(8) . '.csv';
        file_put_contents($csv, "\xEF\xBB\xBFDate;Client;Amount\n2026-10-05;Nordwave GmbH;1200.50\n2026-10-12;Brightline Labs;3892.50\n");

        $response = PryTaxServer::actingAs($owner)->tool(ReadSpreadsheet::class, ['path' => $csv]);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('headers', ['Date', 'Client', 'Amount'])
            ->where('total_rows', 2)
            ->where('rows.1', ['Date' => '2026-10-12', 'Client' => 'Brightline Labs', 'Amount' => '3892.50'])
            ->etc());
    }

    /**
     * Test that an XLSX's sheet is read by its name, with its shared and inline strings and its numbers.
     *
     * @return void
     */
    public function testReadsAnXlsxsSheet(): void
    {
        $owner = $this->claudeOf(Organization::factory()->create());
        $xlsx = $this->files[] = sys_get_temp_dir() . '/prytax-claude-' . Str::random(8) . '.xlsx';
        $this->writeXlsx($xlsx);

        $response = PryTaxServer::actingAs($owner)->tool(ReadSpreadsheet::class, ['path' => $xlsx, 'sheet' => 'Receipts']);

        $response->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('sheets', ['Notes', 'Receipts'])
            ->where('sheet', 'Receipts')
            ->where('headers', ['Client', 'Amount'])
            ->where('rows', [['Client' => 'Nordwave GmbH', 'Amount' => '1200.5'], ['Client' => 'Brightline', 'Amount' => null]])
            ->etc());
    }

    /**
     * Turn Claude on for the organization, by a new owner, with the settings, changes allowed if asked, and get the
     * owner.
     *
     * @param Organization $organization
     * @param array<string, mixed> $settings
     * @param bool $writes
     *
     * @return User
     */
    private function claudeOf(Organization $organization, array $settings = [], bool $writes = false): User
    {
        $owner = $this->userOf($organization);
        Integration::factory()->enabled($owner, 'prytax_' . Str::random(40))->create([
            ...$settings,
            ...($writes ? ['writes_enabled_at' => now(), 'writes_enabled_by_user_id' => $owner->id] : []),
        ]);

        return $owner;
    }

    /**
     * Create a draft invoice of the profile.
     *
     * @param Profile $profile
     *
     * @return mixed
     */
    private function createDraft(Profile $profile): mixed
    {
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile);

        return $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'receiving_account_id' => $account->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'items' => [['quantity' => '1']],
        ]));
    }

    /**
     * Write an XLSX of two sheets: Notes, and Receipts with a header row, a shared string, an inline one and a number.
     *
     * @param string $path
     *
     * @return void
     */
    private function writeXlsx(string $path): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $relationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $relationships . '"><sheets>'
            . '<sheet name="Notes" sheetId="1" r:id="rId1"/><sheet name="Receipts" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<si><t>Client</t></si><si><t>Amount</t></si><si><r><t>Nordwave</t></r><r><t> GmbH</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1200.5</v></c></row>'
            . '<row r="3"><c r="A3" t="inlineStr"><is><t>Brightline</t></is></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();
    }
}
