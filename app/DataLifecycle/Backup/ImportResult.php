<?php

namespace PryTax\DataLifecycle\Backup;

/**
 * Class ImportResult.
 *
 * What an import wrote, or would write in a dry run: the new tenant, the rows of each table, the documents and the
 * files rows left out, since the archive doesn't hold their documents.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup
 */
final class ImportResult
{
    /**
     * ImportResult constructor.
     *
     * @param int|null $tenantId Id of the new tenant, or null after a dry run.
     * @param array<string, int> $rows Rows imported of each table.
     * @param int $documents
     * @param int $skippedFiles Rows of documents, which the archive doesn't hold.
     * @param array<string, mixed> $checks
     * @param bool $dryRun
     */
    public function __construct(
        public readonly ?int $tenantId,
        public readonly array $rows,
        public readonly int $documents,
        public readonly int $skippedFiles,
        public readonly array $checks,
        public readonly bool $dryRun,
    ) {}
}
