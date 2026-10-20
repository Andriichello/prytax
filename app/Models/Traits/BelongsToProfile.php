<?php

namespace PryTax\Models\Traits;

use Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Profile;

/**
 * Trait BelongsToProfile.
 *
 * For models of a profile (with `profile_id`, the tenant key of the domain tables, design.md §0): a global scope
 * limits their queries to CurrentProfile, and to no records at all, when it isn't set, so a forgotten context fails
 * closed. A new record gets the current profile, and a record of another profile can't be saved in it. Without a
 * current profile (console, seeders), records are saved with the profile, which they are given.
 *
 * @mixin BaseModel
 * @property int $profile_id
 * @property Profile $profile
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models\Traits
 */
trait BelongsToProfile
{
    /**
     * Name of the global scope, which limits queries to the profile.
     *
     * @var string
     */
    public const string PROFILE_SCOPE = 'profile';

    /**
     * Boot the trait, registering its global scope and model event listeners.
     *
     * @return void
     */
    public static function bootBelongsToProfile(): void
    {
        static::addGlobalScope(self::PROFILE_SCOPE, function (Builder $query) {
            $id = CurrentProfile::id();

            if ($id === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->qualifyColumn('profile_id'), $id);
        });

        static::saving(fn (self $model) => $model->assignCurrentProfile());
    }

    /**
     * Profile, to which the model belongs.
     *
     * @return BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    /**
     * Give the model the current profile, when it has none, and make sure it isn't saved in a profile, which isn't
     * the current one.
     *
     * @return void
     * @throws LogicException
     */
    protected function assignCurrentProfile(): void
    {
        $current = CurrentProfile::id();
        $profileId = $this->getAttribute('profile_id');

        if ($profileId === null) {
            if ($current === null) {
                throw new LogicException(static::class . ' has no profile, and none is current.');
            }

            $this->setAttribute('profile_id', $current);

            return;
        }

        if ($current !== null && (int) $profileId !== $current) {
            throw new LogicException(static::class . ' of another profile can\'t be saved in the current one.');
        }
    }
}
