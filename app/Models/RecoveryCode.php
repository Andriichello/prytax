<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use PryTax\Queries\GlobalQuery;

/**
 * Class RecoveryCode.
 *
 * The code, which sets a new password of an installation of one organization (builds.md §1.4), where the password
 * alone signs in: «K7Q2M-9XTRA-4HWPE-LD3VN», from letters and digits nobody confuses, encrypted with the
 * installation's key, so the recovery kit can show it again. When its owner kept it is recorded, outside the desktop
 * app (which records it beside its database).
 *
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property Carbon|null $kept_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @method static GlobalQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class RecoveryCode extends BaseModel
{
    /**
     * The characters of a code: no 0, O, 1, I or L, which are read for each other.
     *
     * @var string
     */
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'code',
        'kept_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'code' => 'encrypted',
        'kept_at' => 'datetime',
    ];

    /**
     * Get the user, whose password it sets.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Make a new code: four groups of five characters.
     *
     * @return string
     */
    public static function generate(): string
    {
        $characters = '';

        for ($index = 0; $index < 20; $index++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 5));
    }

    /**
     * Get a code as typed, comparable with a stored one: upper case, without spaces or dashes.
     *
     * @param string $code
     *
     * @return string
     */
    public static function normalize(string $code): string
    {
        return Str::upper((string) preg_replace('/[\s-]+/', '', $code));
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return GlobalQuery
     */
    public function newEloquentBuilder($query): GlobalQuery
    {
        return new GlobalQuery($query);
    }
}
