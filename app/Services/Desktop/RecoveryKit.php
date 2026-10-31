<?php

namespace PryTax\Services\Desktop;

use Carbon\CarbonImmutable;
use Core\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PryTax\Helpers\Desktop;
use PryTax\Models\RecoveryCode;
use PryTax\Services\Setup\SingleOrganization;

/**
 * Class RecoveryKit.
 *
 * The installation's recovery kit (builds.md §4.5, §1.4): a text with its organizations and, in the desktop app, its
 * key and the way to restore it on another computer (without the key, a copy of the database can't be read); in one
 * person's installation (`prytax.single_organization`) also the recovery code, which sets a new password when it's
 * forgotten, and outside the desktop app only that. The owner keeps it away from the computer before working; when
 * they did is recorded beside the database in the desktop app, else on the recovery code. It's never sent anywhere.
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
     * @param string $databasePath The desktop app's database, or `''` outside it.
     * @param SingleOrganization $single
     */
    public function __construct(protected InstallationKey $key, protected string $databasePath, protected SingleOrganization $single) {}

    /**
     * Determine whether the installation has a kit: the desktop app's, or one person's with its account.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return Desktop::isRunning() || $this->hasCode();
    }

    /**
     * Determine whether the kit holds a recovery code: one person's installation with its account.
     *
     * @return bool
     */
    public function hasCode(): bool
    {
        return $this->code() !== null;
    }

    /**
     * Get the kit's text, in the current language.
     *
     * @return string
     */
    public function text(): string
    {
        $desktop = Desktop::isRunning();
        $code = $this->code();

        return implode("\n", array_filter([
            __('PryTax recovery kit'),
            __('Created: :date', ['date' => now()->format('d.m.Y H:i')]),
            $desktop ? __('Database: :path', ['path' => $this->databasePath]) : null,
            '',
            __('Organizations:'),
            ...$this->organizations()->map(fn (string $name) => "- $name"),
            '',
            ...($desktop ? [__('Key: :key', ['key' => config('app.key')]), ''] : []),
            ...($code !== null ? [__('Recovery code: :code', ['code' => $code]), ''] : []),
            ...($desktop ? [__('The key decrypts the tax IDs, IBANs and invoice details of this computer\'s PryTax database: without it, a copy of the database can\'t be read. Keep this file away from this computer, such as in a password manager or printed.'), ''] : []),
            ...($code !== null ? [($desktop
                ? __('The recovery code, or the key above, sets a new password on «Forgot password?» of the sign-in screen: it is the only way back in when the password is forgotten.')
                : __('The recovery code, or this installation\'s APP_KEY, sets a new password on «Forgot password?» of the sign-in screen: it is the only way back in when the password is forgotten. Keep it away from this computer, such as in a password manager or printed.')), ''] : []),
            ...($desktop ? [__('To restore PryTax on another computer: install it, choose «Restore» on its first screen, and give it this file and the database file above.'), ''] : []),
        ], fn (?string $line) => $line !== null));
    }

    /**
     * Get when the owner kept the kit, or null, until they do.
     *
     * @return CarbonImmutable|null
     */
    public function savedAt(): ?CarbonImmutable
    {
        if (Desktop::isRunning()) {
            return $this->key->recoveryKitSavedAt();
        }

        $account = $this->single->account();
        $kept = $account === null ? null : RecoveryCode::query()->where('user_id', $account->id)->value('kept_at');

        return $kept === null ? null : CarbonImmutable::parse($kept);
    }

    /**
     * Record that the owner kept the kit, saved to a file (`file`) or copied (`copy`), and get when.
     *
     * @param string $via
     *
     * @return CarbonImmutable
     */
    public function markSaved(string $via): CarbonImmutable
    {
        if (Desktop::isRunning()) {
            return $this->key->markRecoveryKitSaved($via);
        }

        $now = CarbonImmutable::now();
        $account = $this->single->account();

        if ($account !== null) {
            $this->single->codeOf($account)->forceFill(['kept_at' => $now])->save();
        }

        return $now;
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

    /**
     * Get the recovery code of one person's installation, or null without one.
     *
     * @return string|null
     */
    protected function code(): ?string
    {
        $account = $this->single->account();

        return $account === null ? null : $this->single->codeOf($account)->code;
    }
}
