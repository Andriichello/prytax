<?php

namespace PryTax\Services\Alerts;

use Illuminate\Support\Facades\DB;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Alert;
use PryTax\Models\Profile;

/**
 * Class AlertSync.
 *
 * Keeps a profile's notifications in step with their conditions (design.md §9): upserts one row per dedupe key,
 * which AlertCollector reports, reopening a resolved one whose condition holds again, and resolves the rows, whose
 * conditions cleared. Rows are never deleted, and read state is never touched here. It runs after the writes, which
 * can change the conditions, and daily.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Alerts
 */
class AlertSync
{
    /**
     * Whether syncing after writes is paused, such as while seeding.
     *
     * @var bool
     */
    protected static bool $paused = false;

    /**
     * AlertSync constructor.
     *
     * @param AlertCollector $collector
     */
    public function __construct(
        protected AlertCollector $collector
    ) {}

    /**
     * Run the callback without syncing after its writes.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    public static function paused(callable $callback): mixed
    {
        $previous = static::$paused;
        static::$paused = true;

        try {
            return $callback();
        } finally {
            static::$paused = $previous;
        }
    }

    /**
     * Sync the profile's notifications once the current transaction commits, or now, outside of one.
     *
     * @param Profile $profile
     *
     * @return void
     */
    public function syncAfterCommit(Profile $profile): void
    {
        if (static::$paused) {
            return;
        }

        DB::afterCommit(fn () => $this->sync($profile));
    }

    /**
     * Sync the profile's notifications with their conditions.
     *
     * @param Profile $profile
     *
     * @return array{opened: int, resolved: int}
     */
    public function sync(Profile $profile): array
    {
        return CurrentProfile::run($profile, function () use ($profile) {
            $collected = $this->collector->collect($profile);
            $existing = Alert::query()->get()->keyBy('dedupe_key');
            $opened = 0;
            $resolved = 0;

            foreach ($collected as $key => $attributes) {
                $alert = $existing->get($key) ?? new Alert(['dedupe_key' => $key]);
                $opened += $alert->exists && $alert->resolved_at === null ? 0 : 1;

                $alert->fill([...$attributes, 'resolved_at' => null]);
                $alert->profile_id = $profile->getKey();
                $alert->save();
            }

            foreach ($existing as $key => $alert) {
                if (!isset($collected[$key]) && $alert->resolved_at === null) {
                    $alert->forceFill(['resolved_at' => now()])->save();
                    $resolved++;
                }
            }

            return ['opened' => $opened, 'resolved' => $resolved];
        });
    }
}
