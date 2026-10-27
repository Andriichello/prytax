<?php

namespace PryTax\DataLifecycle\Backup\Internal;

/**
 * Class Document.
 *
 * An object, which an archive holds under `documents/`: a row of a table of documents, read through its own disk.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class Document
{
    /**
     * Document constructor.
     *
     * @param string $disk
     * @param string $path
     * @param string $archivePath
     * @param int $bytes
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $archivePath,
        public readonly int $bytes,
    ) {}
}
