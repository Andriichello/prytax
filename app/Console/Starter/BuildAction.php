<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Number;
use PryTax\Console\Commands\Start;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Class BuildAction.
 *
 * What PryTax builds (`./prytax build`): the SPA, the desktop app's Linux installers (builds.md §4.9; Windows and
 * macOS come from the CI) and the invoice styles' thumbnails. A desktop build reinstalls Electron, whose sandbox then
 * needs fixing again.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class BuildAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        // The desktop app's installers need Electron on this machine, not in the starter's container
        $targets = multiselect('What should be built?', array_filter([
            'spa' => 'The SPA (npm run build)',
            'desktop' => $this->toolchain->inContainer() ? null : 'The desktop app for Linux: AppImage and deb (native:build linux x64)',
            'thumbnails' => 'The invoice styles\' thumbnails (pdftoppm)',
        ]), default: ['spa'], required: true);

        $built = true;

        foreach ($targets as $target) {
            $built = match ($target) {
                'spa' => $this->run(['npm', 'run', 'build']),
                'desktop' => $this->desktop(),
                'thumbnails' => $this->artisan(['invoice:style-thumbnails']),
            } && $built;
        }

        return $built ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Build the desktop app's Linux installers, list them, and tell when the sandbox needs fixing again.
     *
     * @return bool
     */
    protected function desktop(): bool
    {
        $built = $this->artisan(['native:build', 'linux', 'x64', '--no-interaction']);
        $installers = glob(base_path('nativephp/electron/dist/*.{AppImage,deb}'), GLOB_BRACE) ?: [];

        if ($installers !== []) {
            table(['Installer', 'Size'], array_map(fn (string $file) => [$file, Number::fileSize((int) filesize($file))], $installers));
        }

        note('Windows and macOS installers come from the CI: .github/workflows/desktop.yml (builds.md §4.9).');
        $sandbox = $this->doctor->sandbox();

        if ($sandbox->failed()) {
            warning('The build reinstalled Electron: its sandbox needs fixing again for the development window.');
            note((string) $sandbox->hint);
        }

        return $built;
    }
}
