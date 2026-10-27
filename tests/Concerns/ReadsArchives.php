<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\Internal\ArchiveWriter;
use PryTax\DataLifecycle\Backup\Internal\Csv;
use PryTax\DataLifecycle\Backup\Internal\DataArchive;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\Models\Profile;
use ZipArchive;

/**
 * Trait ReadsArchives.
 *
 * Helpers of tests, which write a profile's archive and read its files back.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Concerns
 */
trait ReadsArchives
{
    /**
     * Write the profile's archive, with its documents.
     *
     * @param Profile $profile
     * @param array<string, bool> $options
     *
     * @return DataArchive
     */
    protected function writeArchive(Profile $profile, array $options = []): DataArchive
    {
        $writer = app(ArchiveWriter::class);
        $archive = $writer->writeData($profile->id, new RunSpec(options: [RunSpec::INCLUDE_DOCUMENTS => true, ...$options]), DB::connection(), [
            'snapshot_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'organization' => ['id' => $profile->organization_id, 'name' => 'Test'],
        ]);

        $writer->addDocuments($archive);

        return $archive;
    }

    /**
     * Read a file of the archive.
     *
     * @param DataArchive|string $archive
     * @param string $path
     *
     * @return string|null
     */
    protected function archiveFile(DataArchive|string $archive, string $path): ?string
    {
        $zip = new ZipArchive;
        $zip->open($archive instanceof DataArchive ? $archive->path : $archive);
        $contents = $zip->getFromName($path);
        $zip->close();

        return $contents === false ? null : $contents;
    }

    /**
     * Read the rows of a CSV of the archive, by its header.
     *
     * @param DataArchive|string $archive
     * @param string $path
     *
     * @return array<int, array<string, string|null>>
     */
    protected function archiveRows(DataArchive|string $archive, string $path): array
    {
        $rows = Csv::parse((string) $this->archiveFile($archive, $path));
        $header = array_shift($rows) ?? [];

        return array_map(fn (array $row) => array_combine($header, $row), $rows);
    }
}
