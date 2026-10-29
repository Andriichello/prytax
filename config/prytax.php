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

];
