<?php

namespace PryTax\DataLifecycle\Backup;

use Carbon\CarbonImmutable;

/**
 * Class RunSpec.
 *
 * What a run exports: its kind, its options (`include_documents`, `include_audit_log`, which optional tables and the
 * documents need) and its scope, the whole history or a period (backups.md §2.7). Only a full one is restorable.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup
 */
final class RunSpec
{
    /**
     * Option, with which the archives hold their documents.
     *
     * @var string
     */
    public const string INCLUDE_DOCUMENTS = 'include_documents';

    /**
     * RunSpec constructor.
     *
     * @param string $kind `scheduled`, `manual`, `automatic` or `export`.
     * @param array<string, bool> $options
     * @param CarbonImmutable|null $from First day of a period, or null for the whole history.
     * @param CarbonImmutable|null $to Last day of a period.
     */
    public function __construct(
        public readonly string $kind = 'manual',
        public readonly array $options = [self::INCLUDE_DOCUMENTS => true],
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
    ) {}

    /**
     * Determine if the run has the option.
     *
     * @param string $option
     *
     * @return bool
     */
    public function has(string $option): bool
    {
        return (bool) ($this->options[$option] ?? false);
    }

    /**
     * Determine if the archives hold their documents.
     *
     * @return bool
     */
    public function includesDocuments(): bool
    {
        return $this->has(self::INCLUDE_DOCUMENTS);
    }

    /**
     * Determine if the run exports a period only.
     *
     * @return bool
     */
    public function isPeriod(): bool
    {
        return $this->from !== null;
    }

    /**
     * Determine if an archive of the run can be imported: only a full one is.
     *
     * @return bool
     */
    public function isRestorable(): bool
    {
        return !$this->isPeriod();
    }

    /**
     * Get the scope, as the manifest and the run record it.
     *
     * @return array{type: string, from?: string, to?: string}
     */
    public function scope(): array
    {
        return $this->isPeriod()
            ? ['type' => 'period', 'from' => $this->from->toDateString(), 'to' => $this->to?->toDateString()]
            : ['type' => 'full'];
    }
}
