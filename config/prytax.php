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
    | and local development turn it on.
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

];
