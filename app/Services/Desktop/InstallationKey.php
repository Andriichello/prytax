<?php

namespace PryTax\Services\Desktop;

use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;

/**
 * Class InstallationKey.
 *
 * The application key of a desktop installation (APP-BUILDS-PLAN.md, phase 3, step 5), which encrypts its tax IDs,
 * IBANs and invoice snapshots. The bundle carries no key, so each installation makes its own on the first launch and
 * keeps it beside its database (`database.sqlite.key`, readable by the user only); the recovery kit is its copy for
 * a new computer. While developing (`native:run` with the `.env`'s key), and for a database with data but no key
 * file, which the bundled key encrypted, the bundled key is kept instead.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Desktop
 */
class InstallationKey
{
    /**
     * InstallationKey constructor.
     *
     * @param string $databasePath The desktop's database file
     */
    public function __construct(protected string $databasePath) {}

    /**
     * Get the path of the key file.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->databasePath . '.key';
    }

    /**
     * Get the key: the stored one, or one stored now.
     *
     * @param string|null $bundled The key of the bundle's `.env`, if it has one
     *
     * @return string
     */
    public function resolve(?string $bundled): string
    {
        $stored = $this->stored();

        if ($stored !== null) {
            return $stored;
        }

        // Kept while developing, where `native:seed` and the like encrypt with it, and for data encrypted with it
        $hasData = is_file($this->databasePath) && filesize($this->databasePath) > 0;
        $keepBundled = $bundled !== null && $bundled !== '' && (config('app.debug') || $hasData);

        return $this->store($keepBundled ? $bundled : 'base64:' . base64_encode(Encrypter::generateKey(config('app.cipher'))));
    }

    /**
     * Get when the owner kept the recovery kit, or null, until they do.
     *
     * @return CarbonImmutable|null
     */
    public function recoveryKitSavedAt(): ?CarbonImmutable
    {
        $saved = is_file($this->recoveryPath()) ? json_decode((string) file_get_contents($this->recoveryPath()), true) : null;

        return isset($saved['saved_at']) ? CarbonImmutable::parse($saved['saved_at']) : null;
    }

    /**
     * Record that the owner kept the recovery kit: saved to a file, or copied.
     *
     * @param string $via `file` or `copy`
     *
     * @return CarbonImmutable
     */
    public function markRecoveryKitSaved(string $via): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        file_put_contents($this->recoveryPath(), json_encode(['saved_at' => $now->toIso8601String(), 'via' => $via]));

        return $now;
    }

    /**
     * Get the stored key, or null.
     *
     * @return string|null
     */
    protected function stored(): ?string
    {
        $key = is_file($this->path()) ? trim((string) file_get_contents($this->path())) : '';

        return $key === '' ? null : $key;
    }

    /**
     * Store the key, readable by the user only. The processes of the first launch (the server, the worker, the
     * scheduler) may race: the first one's key is linked into place at once, and the others take it.
     *
     * @param string $key
     *
     * @return string The stored key
     */
    protected function store(string $key): string
    {
        $directory = dirname($this->path());

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $temporary = tempnam($directory, 'key');
        file_put_contents($temporary, $key);
        chmod($temporary, 0600);

        $linked = @link($temporary, $this->path());
        unlink($temporary);

        return $linked ? $key : ($this->stored() ?? $key);
    }

    /**
     * Get the path of the record of the recovery kit.
     *
     * @return string
     */
    protected function recoveryPath(): string
    {
        return $this->databasePath . '.recovery';
    }
}
