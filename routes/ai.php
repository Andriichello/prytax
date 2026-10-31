<?php

use Laravel\Mcp\Facades\Mcp;
use PryTax\Mcp\Servers\PryTaxServer;

/*
 * PryTax's local MCP server for Claude (builds.md §7): Claude Code or Claude Desktop starts it as
 * `php artisan mcp:start prytax`, with the key of an organization's Claude integration, which an owner turns on in
 * Налаштування → Інтеграції. Laravel MCP loads this file itself.
 */
Mcp::local('prytax', PryTaxServer::class);
