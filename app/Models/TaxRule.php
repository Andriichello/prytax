<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Database\Factories\TaxRuleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use PryTax\Queries\TaxRuleQuery;

/**
 * Class TaxRule.
 *
 * Numbers of 3 група in effect from a date (design.md §1): the ЄП and ВЗ rates, the minimum wage, ЄСВ, the income
 * cap and its excess rate. A rule, which any receipt, refund or ЄСВ month references, is immutable, so a new rule
 * never changes existing snapshots.
 *
 * @property int $id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $single_tax_rate
 * @property string $military_levy_rate
 * @property string $excess_rate
 * @property string $min_wage
 * @property string $esv_monthly
 * @property bool $esv_mandatory
 * @property int $esv_due_day
 * @property string $income_cap_uah
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static TaxRuleQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(TaxRuleFactory::class)]
class TaxRule extends BaseModel
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'effective_from',
        'effective_to',
        'single_tax_rate',
        'military_levy_rate',
        'excess_rate',
        'min_wage',
        'esv_monthly',
        'esv_mandatory',
        'esv_due_day',
        'income_cap_uah',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'single_tax_rate' => 'decimal:4',
        'military_levy_rate' => 'decimal:4',
        'excess_rate' => 'decimal:4',
        'min_wage' => 'decimal:2',
        'esv_monthly' => 'decimal:2',
        'esv_mandatory' => 'boolean',
        'esv_due_day' => 'int',
        'income_cap_uah' => 'decimal:2',
    ];

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    protected static function booted(): void
    {
        $guard = function (TaxRule $rule) {
            if ($rule->isReferenced()) {
                throw new LogicException('The tax rule is referenced by receipts, so it can\'t be changed.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * Determine if any receipt, refund or ЄСВ month references the rule.
     *
     * @return bool
     */
    public function isReferenced(): bool
    {
        return Payment::query()->withoutGlobalScopes()->where('tax_rule_id', $this->getKey())->exists()
            || Refund::query()->withoutGlobalScopes()->where('tax_rule_id', $this->getKey())->exists()
            || EsvObligation::query()->withoutGlobalScopes()->where('tax_rule_id', $this->getKey())->exists();
    }

    /**
     * Receipts, whose snapshot uses the rule.
     *
     * @return HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return TaxRuleQuery
     */
    public function newEloquentBuilder($query): TaxRuleQuery
    {
        return new TaxRuleQuery($query);
    }
}
