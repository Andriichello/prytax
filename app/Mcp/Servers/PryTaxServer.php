<?php

namespace PryTax\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use PryTax\Mcp\DatabaseKey;
use PryTax\Mcp\Methods\RecordedInitialize;
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

/**
 * Class PryTaxServer.
 *
 * PryTax's local MCP server for Claude (builds.md §7), which Claude Code or Claude Desktop starts as
 * `php artisan mcp:start prytax` with an organization's integration key: read-only tools over the organization's ФОП
 * (ФОП and overview, invoices and clients, income and books, taxes and alerts, backups), as its acting user, only while
 * the integration is on. Stdout is the protocol's, so PHP's errors go to stderr; a database with its own key beside it
 * (the desktop app's) is read with it; the handshake records the client.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Servers
 */
#[Name('PryTax')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    PryTax is bookkeeping for Ukrainian sole proprietors (ФОП) of the 3rd group of the simplified tax system: their income (receipts of invoices, often in foreign currency, counted in UAH at the NBU rate), the single tax (ЄП, single_tax), the military levy (ВЗ, military_levy), the social contribution (ЄСВ, social_contribution), their invoices and clients, and the income cap of the group.
    These tools only read, as the user the organization's owner chose, and only the ФОП they allowed. Name a ФОП by its id or part of its name (list-profiles lists them); it may be left out when there is one. Periods are a year (2026), a quarter (year and quarter 1–4), or a month; this year or quarter by default. Amounts are strings with two decimals, in UAH unless a currency is given; dates are YYYY-MM-DD.
    Answer in the user's language; keep ФОП, ЄП, ВЗ and ЄСВ as they are. If a tool says Claude is turned off, or its key opens nothing, tell the user that the organization's owner turns Claude on, and copies its connection, in PryTax: Налаштування → Інтеграції (Settings → Integrations).
    TEXT)]
class PryTaxServer extends Server
{
    /**
     * The tools, which only read.
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
