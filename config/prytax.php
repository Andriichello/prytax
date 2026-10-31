<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Whether anyone may register a new organization, with its owner, from the
    | sign-in page (`POST /api/registration`). Off by default: on a server
    | anyone reaching its address could create organizations. The desktop app
    | and local development turn it on. One person's installation registers
    | its only organization either way (`single_organization`).
    |
    */

    'registration' => (bool) env('PRYTAX_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Desktop DevTools
    |--------------------------------------------------------------------------
    |
    | Whether the desktop app's window opens DevTools: unset, whenever
    | APP_DEBUG is on, as NativePHP does. The starter (./prytax desktop) turns
    | them off unless --devtools asks for them.
    |
    */

    'desktop_devtools' => env('PRYTAX_DEVTOOLS'),

    /*
    |--------------------------------------------------------------------------
    | Full backups
    |--------------------------------------------------------------------------
    |
    | Whether owners may back up the whole installation (every organization,
    | its documents and key) and restore one in its place, after comparing
    | them (builds.md §4.11). Only where PryTax runs on a SQLite file for one
    | person: the desktop app, which always allows it, and a web app run on
    | this computer. Never on a shared server, where a restore would replace
    | every other organization.
    |
    */

    'full_backups' => (bool) env('PRYTAX_FULL_BACKUPS', false),

    /*
    |--------------------------------------------------------------------------
    | One organization
    |--------------------------------------------------------------------------
    |
    | Whether the installation is one person's (builds.md §1.4): a single
    | organization, registered with a password alone (its e-mail and name are
    | made up), signed in with the password alone while it has one user, and
    | a recovery code (or the installation's key) setting a new password when
    | it's forgotten. The desktop app always is; a web app on this computer or
    | a server kept for one person may be.
    |
    */

    'single_organization' => (bool) env('PRYTAX_SINGLE_ORGANIZATION', false),

    /*
    |--------------------------------------------------------------------------
    | Claude's key
    |--------------------------------------------------------------------------
    |
    | The key of an organization's Claude integration (builds.md §7), which
    | Claude Code or Claude Desktop gives the local MCP server it starts
    | (`php artisan mcp:start prytax`): it names the integration, which serves
    | only while it's on in Налаштування → Інтеграції. Never set in `.env`:
    | the connection shown there carries it.
    |
    */

    'mcp_key' => env('PRYTAX_MCP_KEY'),

];
