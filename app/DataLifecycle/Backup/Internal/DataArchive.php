<?php

namespace PryTax\DataLifecycle\Backup\Internal;

/**
 * Class DataArchive.
 *
 * A tenant's archive on the worker's disk after its data is written (step 2 of a run, inside the snapshot): its
 * documents are only listed, and are added after the snapshot is committed, unless the data didn't change.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class DataArchive
{
    /**
     * DataArchive constructor.
     *
     * @param string $path Local path of the zip.
     * @param string $contentHash
     * @param array<int, Document> $documents
     * @param array<string, mixed> $manifest
     * @param string $folder The tenant's folder in a bundle, which its file is named after.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $contentHash,
        public readonly array $documents,
        public readonly array $manifest,
        public readonly string $folder,
    ) {}

    /**
     * Get the rows of each file of data.
     *
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        return array_column($this->manifest['files'], 'rows', 'path');
    }

    /**
     * Delete the local zip.
     *
     * @return void
     */
    public function discard(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
