<?php

namespace PryTax\Http\Controllers;

use Core\Http\Responses\ApiResponse;
use PryTax\Helpers\Desktop;

/**
 * Class EnvironmentController.
 *
 * Where the SPA runs (`GET environment`): in the desktop app's window, it saves files through native dialogs and
 * asks the owner to keep the recovery kit (builds.md §4.2).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class EnvironmentController extends Controller
{
    /**
     * Tell whether the SPA runs in the desktop app, and whether the installation is one person's (builds.md §1.4).
     *
     * @return ApiResponse
     */
    public function show(): ApiResponse
    {
        return ApiResponse::ok(['data' => [
            'desktop' => Desktop::isRunning(),
            'single_organization' => (bool) config('prytax.single_organization'),
        ]]);
    }
}
