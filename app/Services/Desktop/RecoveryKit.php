<?php

namespace PryTax\Services\Desktop;

use Core\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Class RecoveryKit.
 *
 * The desktop installation's recovery kit (APP-BUILDS-PLAN.md, phase 3, step 5): a text with its key, its
 * organizations and the way to restore it on another computer. Without the key, a copy of the database can't be
 * read, so the owner keeps the kit away from the computer before working. It's never sent anywhere.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Desktop
 */
class RecoveryKit
{
    /**
     * RecoveryKit constructor.
     *
     * @param InstallationKey $key
     * @param string $databasePath
     */
    public function __construct(protected InstallationKey $key, protected string $databasePath) {}

    /**
     * Get the kit's text, in the current language.
     *
     * @return string
     */
    public function text(): string
    {
        return implode("\n", [
            __('PryTax recovery kit'),
            __('Created: :date', ['date' => now()->format('d.m.Y H:i')]),
            __('Organizations: :names', ['names' => $this->organizations()->implode(', ')]),
            __('Database: :path', ['path' => $this->databasePath]),
            '',
            __('Key: :key', ['key' => config('app.key')]),
            '',
            __('The key decrypts the tax IDs, IBANs and invoice details of this computer\'s PryTax database: without it, a copy of the database can\'t be read. Keep this file away from this computer, such as in a password manager or printed.'),
            '',
            __('To restore PryTax on another computer: install it, choose «Restore» on its first screen, and give it this file and the database file above.'),
            '',
        ]);
    }

    /**
     * Get the kit's file name: `PryTax-recovery-kit-praktyka-2026-10-29.txt`.
     *
     * @return string
     */
    public function filename(): string
    {
        $organization = Str::slug((string) $this->organizations()->first()) ?: 'prytax';

        return 'PryTax-recovery-kit-' . $organization . '-' . now()->toDateString() . '.txt';
    }

    /**
     * Get the names of the installation's organizations.
     *
     * @return Collection<int, string>
     */
    protected function organizations(): Collection
    {
        return Organization::query()->orderBy('id')->pluck('name');
    }
}
