<?php

namespace PryTax\DataLifecycle\Backup\Internal;

/**
 * Class ArchiveFile.
 *
 * A finished archive on the worker's disk, with its sha256 and size.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class ArchiveFile
{
    /**
     * ArchiveFile constructor.
     *
     * @param string $path
     * @param string $sha256
     * @param int $bytes
     */
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $bytes,
    ) {}
}
