<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonInterface;
use PryTax\Enums\DeclarationStatus;
use PryTax\Exceptions\PeriodLockedException;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;

/**
 * Class PeriodLock.
 *
 * Guards quarters, whose declaration was submitted (design.md §8.3). A write of income into such a quarter needs a
 * correction note; with it, the write proceeds, and the quarter and every later declared quarter of the year are
 * marked `corrected`, as declarations are cumulative and an уточнююча декларація is needed for each of them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class PeriodLock
{
    /**
     * Make sure the quarter of the date may be written to: it is open, or the write carries a correction note.
     *
     * @param Profile $profile
     * @param CarbonInterface $date
     * @param string|null $correctionNote
     *
     * @return void
     * @throws PeriodLockedException
     */
    public function assertOpen(Profile $profile, CarbonInterface $date, ?string $correctionNote = null): void
    {
        $period = TaxPeriod::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $date->year)
            ->where('quarter', $date->quarter)
            ->first();

        if (!$period?->isLocked()) {
            return;
        }

        if (blank($correctionNote)) {
            throw new PeriodLockedException(__(
                'Quarter :quarter of :year is locked by its submitted declaration. Give a correction note to change it.',
                ['quarter' => $date->quarter, 'year' => $date->year]
            ));
        }

        $this->markCorrected($period, (string) $correctionNote);
    }

    /**
     * Mark the quarter, and the later quarters of its year, whose declarations were submitted, as needing an
     * уточнююча декларація, keeping the note in the quarter's notes.
     *
     * @param TaxPeriod $period
     * @param string $note
     *
     * @return void
     */
    protected function markCorrected(TaxPeriod $period, string $note): void
    {
        $later = TaxPeriod::query()
            ->where('profile_id', $period->profile_id)
            ->where('year', $period->year)
            ->where('quarter', '>', $period->quarter)
            ->whereIn('declaration_status', [
                DeclarationStatus::Submitted->value,
                DeclarationStatus::Accepted->value,
                DeclarationStatus::Corrected->value,
            ])
            ->get();

        foreach ([$period, ...$later] as $affected) {
            $affected->declaration_status = DeclarationStatus::Corrected;

            if ($affected->is($period)) {
                $affected->notes = trim(($affected->notes ?? '') . "\n" . now()->format('d.m.Y') . ': ' . $note);
            }

            $affected->save();
        }
    }
}
