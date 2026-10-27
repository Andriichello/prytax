<?php

namespace PryTax\DataLifecycle\Retention;

/**
 * Class RetentionResult.
 *
 * What a retention job did: the records it went through, deleted and anonymized, and the stored objects it deleted.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention
 */
final class RetentionResult
{
    /**
     * RetentionResult constructor.
     *
     * @param int $processed
     * @param int $deleted
     * @param int $anonymized
     * @param int $objectsDeleted
     */
    public function __construct(
        public int $processed = 0,
        public int $deleted = 0,
        public int $anonymized = 0,
        public int $objectsDeleted = 0,
    ) {}
}
