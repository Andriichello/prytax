<?php

namespace PryTax\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use PryTax\Mcp\DatabaseKey;
use PryTax\Mcp\Methods\RecordedInitialize;
use PryTax\Mcp\Tools\ApiEndpoints;
use PryTax\Mcp\Tools\ApiRead;
use PryTax\Mcp\Tools\ApiWrite;
use PryTax\Mcp\Tools\GetAccounting;
use PryTax\Mcp\Tools\GetBackupStatus;
use PryTax\Mcp\Tools\GetDeclaration;
use PryTax\Mcp\Tools\GetIncomeBook;
use PryTax\Mcp\Tools\GetInvoice;
use PryTax\Mcp\Tools\GetPracticeOverview;
use PryTax\Mcp\Tools\GetProfile;
use PryTax\Mcp\Tools\GetTaxLedger;
use PryTax\Mcp\Tools\ListAlerts;
use PryTax\Mcp\Tools\ListClients;
use PryTax\Mcp\Tools\ListInvoices;
use PryTax\Mcp\Tools\ListProfiles;
use PryTax\Mcp\Tools\ListReceipts;
use PryTax\Mcp\Tools\ReadSpreadsheet;

/**
 * Class PryTaxServer.
 *
 * PryTax's local MCP server for Claude (builds.md §7), which Claude Code or Claude Desktop starts as
 * `php artisan mcp:start prytax` with an organization's integration key: tools over the organization's ФОП, as its
 * acting user, only while the integration is on: reads (ФОП and overview, invoices and clients, income and books, taxes
 * and alerts, backups), the API's endpoints and any of its reads, a spreadsheet on this computer, and, once an owner
 * allows changes, any of the API's writes. Stdout is the protocol's, so PHP's errors go to stderr; a database with its own key beside it
 * (the desktop app's) is read with it; the handshake records the client.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Servers
 */
#[Name('PryTax')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    PryTax is bookkeeping for Ukrainian sole proprietors (ФОП) of the 3rd group of the simplified tax system: their income (receipts of invoices, often in foreign currency, counted in UAH at the NBU rate), the single tax (ЄП, single_tax), the military levy (ВЗ, military_levy), the social contribution (ЄСВ, social_contribution), their invoices and clients, and the income cap of the group.
    Claude acts as the user the organization's owner chose, with that user's role, and only on the ФОП they allowed. The named tools read; for anything else, api-endpoints lists PryTax's API, api-read reads any of it, and api-write (only once the owner allowed changes) creates, changes and deletes as the app does, with local files as uploads (an invoice's PDF). read-spreadsheet reads a CSV or XLSX on this computer, whose rows Claude can then record with api-write: check the ФОП, client, contract or account ids first (api-read), say what will be recorded, and ask before deleting anything. Name a ФОП by its id or part of its name (list-profiles lists them); it may be left out when there is one. Periods are a year (2026), a quarter (year and quarter 1–4), or a month; this year or quarter by default. Amounts are strings with two decimals, in UAH unless a currency is given; dates are YYYY-MM-DD. For what a ФОП still owes in taxes, quote get-declaration's remaining.<tax>.to_pay: net of payments and of opening balances (an overpayment or a debt carried in); the profiles' taxes_to_pay is only this year's unpaid accruals, before opening balances.
    Answer in the user's language; keep ФОП, ЄП, ВЗ and ЄСВ as they are. If a tool says Claude is turned off, or its key opens nothing, tell the user that the organization's owner turns Claude on, and copies its connection, in PryTax: Налаштування → Інтеграції (Settings → Integrations).
    TEXT)]
class PryTaxServer extends Server
{
    /**
     * How many tools a list gives at once: all of them, which clients don't all page through.
     *
     * @var int
     */
    public int $defaultPaginationLength = 50;

    /**
     * The tools: reads, the API's endpoints and reads, a spreadsheet, and, once changes are allowed, the API's writes.
     *
     * @var array<int, class-string>
     */
    protected array $tools = [
        ListProfiles::class,
        GetProfile::class,
        GetPracticeOverview::class,
        ListInvoices::class,
        GetInvoice::class,
        ListClients::class,
        ListReceipts::class,
        GetAccounting::class,
        GetIncomeBook::class,
        GetTaxLedger::class,
        GetDeclaration::class,
        ListAlerts::class,
        GetBackupStatus::class,
        ApiEndpoints::class,
        ApiRead::class,
        ApiWrite::class,
        ReadSpreadsheet::class,
    ];

    /**
     * Prepare the server, before it handles anything.
     *
     * @return void
     */
    protected function boot(): void
    {
        // Stdout carries the protocol's messages, nothing else
        ini_set('display_errors', 'stderr');
        DatabaseKey::apply();
        $this->addMethod('initialize', RecordedInitialize::class);
    }
}
