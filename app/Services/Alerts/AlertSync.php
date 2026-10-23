<?php

namespace PryTax\Services\Alerts;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PryTax\Enums\AlertSeverity;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Alert;
use PryTax\Models\Profile;

/**
 * Class AlertSync.
 *
 * Keeps a profile's notifications in step with their conditions (design.md §9): upserts one row per dedupe key,
 * which AlertCollector reports, and resolves the rows, whose conditions cleared. Rows are never deleted. A resolved
 * one, whose condition holds again, and one, which grew more severe (a deadline, which passed), are news again: they
 * are dated now and unread by everyone. Otherwise read state is kept. Each one's texts are written in every language
 * of the app (`texts`), and in Ukrainian, PryTax's own, into its columns. It runs after the writes, which can change
 * the conditions, and daily.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Alerts
 */
class AlertSync
{
    /**
     * The language of the texts in the columns of notifications.
     *
     * @var string
     */
    public const string LOCALE = 'uk';

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
                $isNews = $alert->exists && ($alert->resolved_at !== null || $this->escalates($alert, $attributes['severity']));
                $texts = $this->texts($attributes['texts']);
                $own = $texts[self::LOCALE];
                unset($attributes['texts']);

                $alert->fill([
                    ...$attributes,
                    'title' => $own['title'],
                    'body' => $own['body'],
                    'action_label' => $own['action_label'],
                    'payload' => [...$attributes['payload'] ?? [], 'context' => $own['context']],
                    'texts' => $texts,
                    'resolved_at' => null,
                ]);
                $alert->profile_id = $profile->getKey();

                if ($isNews) {
                    $alert->created_at = now();
                    $alert->readers()->detach();
                }

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

    /**
     * Write a notification's texts in every language of the app.
     *
     * @param Closure(): array{title: string, body: string|null, action_label: string|null, context: string|null} $describe
     *
     * @return array<string, array{title: string, body: string|null, action_label: string|null, context: string|null}>
     */
    protected function texts(Closure $describe): array
    {
        $current = App::getLocale();
        $texts = [];

        try {
            foreach (array_unique([self::LOCALE, ...config('api-core.locales')]) as $locale) {
                App::setLocale($locale);
                $texts[$locale] = $describe();
            }
        } finally {
            App::setLocale($current);
        }

        return $texts;
    }

    /**
     * Determine if the notification grows more severe, such as a deadline, which passed.
     *
     * @param Alert $alert
     * @param AlertSeverity|string $severity
     *
     * @return bool
     */
    protected function escalates(Alert $alert, AlertSeverity|string $severity): bool
    {
        $ranks = [AlertSeverity::Info->value => 0, AlertSeverity::Warning->value => 1, AlertSeverity::Error->value => 2];
        $to = $severity instanceof AlertSeverity ? $severity->value : $severity;
        $from = $alert->severity instanceof AlertSeverity ? $alert->severity->value : (string) $alert->severity;

        return ($ranks[$to] ?? 0) > ($ranks[$from] ?? 0);
    }
}
