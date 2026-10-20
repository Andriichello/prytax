<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class Contract.
 *
 * A service agreement with a client: its currency, payment terms, hourly rates by date and charge type, and the
 * template of the line items' description, such as "IT services in accordance to the Service Agreement {name} dated
 * {started_at:F j, Y}", which is expanded when an item is created (the item keeps the expanded text).
 *
 * @property int $id
 * @property int $profile_id
 * @property int $client_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string|null $number
 * @property Carbon $started_at
 * @property Carbon|null $ends_at
 * @property string $currency
 * @property int|null $payment_terms_days
 * @property string|null $description_template
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Client $client
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(ContractFactory::class)]
class Contract extends BaseModel
{
    use Audited;
    use BelongsToProfile;

    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'number',
        'started_at',
        'ends_at',
        'currency',
        'payment_terms_days',
        'description_template',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'started_at' => 'date:Y-m-d',
        'ends_at' => 'date:Y-m-d',
        'payment_terms_days' => 'int',
        'is_active' => 'boolean',
    ];

    /**
     * Client of the contract.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Hourly rates of the contract.
     *
     * @return HasMany
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ContractRate::class)->orderBy('effective_from');
    }

    /**
     * Get the hourly rate in effect on the date for the charge type: the type's own one, or else the contract's
     * default one, or null, when there is neither.
     *
     * @param CarbonInterface $date
     * @param int|null $chargeTypeId
     *
     * @return string|null
     */
    public function rateFor(CarbonInterface $date, ?int $chargeTypeId = null): ?string
    {
        $rates = $this->rates()
            ->where('effective_from', '<=', $date->toDateString())
            ->reorder('effective_from', 'desc')
            ->get();

        $rate = ($chargeTypeId === null ? null : $rates->firstWhere('charge_type_id', $chargeTypeId))
            ?? $rates->firstWhere('charge_type_id', null);

        return $rate?->hourly_rate;
    }

    /**
     * Expand the description template with the contract's fields: `{name}`, `{number}` and dates in PHP's date
     * format, such as `{started_at:F j, Y}` (April 13, 2023). The template's "the {name}" reads naturally both with
     * and without a name, as the spaces around an empty field are collapsed.
     *
     * @return string
     */
    public function describe(): string
    {
        $template = $this->description_template ?? '';

        $text = preg_replace_callback('/\{(\w+)(?::([^}]+))?}/', function (array $match) {
            $value = $this->getAttribute($match[1]);

            if ($value instanceof CarbonInterface) {
                return $value->locale('en')->translatedFormat($match[2] ?? 'Y-m-d');
            }

            return (string) $value;
        }, $template);

        return trim((string) preg_replace('/\s{2,}/', ' ', (string) $text));
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return TenantQuery
     */
    public function newEloquentBuilder($query): TenantQuery
    {
        return new TenantQuery($query);
    }
}
