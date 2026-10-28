<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PryTax\DataLifecycle\Backup\Internal\ArchiveStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class ArchiveController.
 *
 * The signed links of archives on a local disk (backups.md §3.3), which a bucket serves itself: `ArchiveStore::url()`
 * signs the disk, the path and the name, and the archive downloads under its name. Without the signature, or once it
 * expired, a link is a 403.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers
 */
class ArchiveController
{
    /**
     * ArchiveController constructor.
     *
     * @param ArchiveStore $store
     */
    public function __construct(protected ArchiveStore $store) {}

    /**
     * Register the route, before the SPA's, which answers every other path.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::get('backups-storage/{path}', [static::class, 'download'])
            ->where('path', '.*')
            ->middleware('signed:relative')
            ->name('backups.archive');
    }

    /**
     * Download an archive under its name.
     *
     * @param Request $request
     * @param string $path
     *
     * @return StreamedResponse
     */
    public function download(Request $request, string $path): StreamedResponse
    {
        $response = $this->store->download((string) $request->query('disk'), $path, (string) $request->query('name'));

        abort_if($response === null, 404);

        return $response;
    }
}
