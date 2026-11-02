<?php

namespace PryTax\DataLifecycle\Contracts;

/**
 * Interface ArchiveUpcaster.
 *
 * Reads the rows of an archive of an older format into those of the next one (backups.md D11): a format version is
 * bumped for a breaking change of the registry only, and every older archive is imported through the upcasters from
 * its version up, in turn, before the import checks and writes the rows.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Contracts
 */
interface ArchiveUpcaster
{
    /**
     * Get the format version, which the upcaster reads: it gives the rows of the next one.
     *
     * @return int
     */
    public function from(): int;

    /**
     * Turn the archive's rows, by table, into the next format's.
     *
     * @param array<string, array<int, array<string, string|null>>> $data
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    public function upcast(array $data): array;
}
