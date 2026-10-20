<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PryTax\Enums\DeclarationStatus;
use PryTax\Exceptions\PeriodLockedException;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;

/**
 * Class PeriodLock.
 *
 * Guards quarters, whose declaration was submitted (design.md §8.3). Declarations are cumulative, so a write of
 * income into a quarter changes it and every later quarter of its year: when any of them is declared, the write needs
 * a correction note; with it, the write proceeds, and each declared quarter from the written one on is marked
 * `corrected`, as an уточнююча декларація is needed for each of them. Writers call it inside their transaction, so a
 * write, which fails afterwards, marks nothing.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class PeriodLock
{
    /**
     * Make sure the quarters of the dates may be written to: neither they nor a later quarter of their year are
     * declared, or the write carries a correction note.
     *
     * @param Profile $profile
     * @param CarbonInterface|array<int, CarbonInterface> $dates
     * @param string|null $correctionNote
     *
     * @return void
     * @throws PeriodLockedException
     */
    public function assertOpen(Profile $profile, CarbonInterface|array $dates, ?string $correctionNote = null): void
    {
        $earliest = collect(is_array($dates) ? $dates : [$dates])
            ->groupBy(fn (CarbonInterface $date) => $date->year)
            ->map(fn ($inYear) => $inYear->min(fn (CarbonInterface $date) => $date->quarter));

        foreach ($earliest as $year => $quarter) {
            $locked = TaxPeriod::query()
                ->where('profile_id', $profile->getKey())
                ->where('year', $year)
                ->where('quarter', '>=', $quarter)
                ->whereNotNull('locked_at')
                ->orderBy('quarter')
                ->get();

            if ($locked->isEmpty()) {
                continue;
            }

            if (blank($correctionNote)) {
                $first = $locked->first();

                throw new PeriodLockedException($first->quarter === $quarter
                    ? __('Quarter :quarter of :year is locked by its submitted declaration. Give a correction note to change it.', [
                        'quarter' => $quarter, 'year' => $year,
                    ])
                    : __('Quarter :later of :year is locked by its submitted declaration, which counts quarter :quarter too. Give a correction note to change it.', [
                        'later' => $first->quarter, 'quarter' => $quarter, 'year' => $year,
                    ]));
            }

            $this->markCorrected($profile, (int) $year, (int) $quarter, $locked, (string) $correctionNote);
        }
    }

    /**
     * Get the first declared quarter, which a write of income on the date would change (its own or a later one of
     * its year), so a form can ask for a correction note before saving, or null.
     *
     * @param Profile $profile
     * @param CarbonInterface $date
     *
     * @return TaxPeriod|null
     */
    public function lockedBy(Profile $profile, CarbonInterface $date): ?TaxPeriod
    {
        return TaxPeriod::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $date->year)
            ->where('quarter', '>=', $date->quarter)
            ->whereNotNull('locked_at')
            ->orderBy('quarter')
            ->first();
    }

    /**
     * Mark the declared quarters as needing an уточнююча декларація, keeping the note in the written quarter's notes.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     * @param Collection<int, TaxPeriod> $locked
     * @param string $note
     *
     * @return void
     */
    protected function markCorrected(Profile $profile, int $year, int $quarter, Collection $locked, string $note): void
    {
        foreach ($locked as $period) {
            $period->declaration_status = DeclarationStatus::Corrected;
            $period->save();
        }

        $written = $locked->firstWhere('quarter', $quarter) ?? TaxPeriod::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->first();

        if ($written) {
            $written->notes = trim(($written->notes ?? '') . "\n" . now()->format('d.m.Y') . ': ' . $note);
            $written->save();
        }
    }
}
