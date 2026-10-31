<?php

namespace PryTax\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;
use PryTax\Services\Desktop\LaunchCatchUp;

/**
 * Class NativeAppServiceProvider.
 *
 * The desktop app's window (builds.md §4.2), which NativePHP opens once it has booted: at least 600 px
 * wide, so the desktop layout (design.md §17.6) always applies (with the sidebar a rail of icons below 1024 px), and
 * as the user left it. DevTools open with it while debugging, unless `prytax.desktop_devtools` says otherwise (the
 * starter turns them off, builds.md §6).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Providers
 */
class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Open the window, once the native application has booted, and catch up on what the night would have done.
     *
     * @return void
     */
    public function boot(): void
    {
        app(LaunchCatchUp::class)->dispatch();
        $devtools = config('prytax.desktop_devtools');

        Window::open()
            ->showDevTools(is_bool($devtools) ? $devtools : (bool) config('app.debug'))
            ->title('PryTax')
            ->width(1280)
            ->height(800)
            ->minWidth(600)
            ->minHeight(640)
            // Chromium's PDF viewer, for the invoice's preview, which opens its PDF in a window of its own
            ->webPreferences(['plugins' => true])
            ->rememberState();
    }

    /**
     * Get the php.ini directives of the desktop's PHP: PDFs and archives of backups take more than the default, and an
     * upload stays on this computer, so it may be as large as an import takes (200 MB: Відновити з архіву, Реєстрація
     * from archives). They apply to every PHP process NativePHP starts, the queue worker's too, so no time limit is set
     * here: a backup may take half an hour.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            'upload_max_filesize' => '200M',
            'post_max_size' => '210M',
        ];
    }
}
