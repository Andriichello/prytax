<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Database\Factories\TaxPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use PryTax\Enums\DeclarationStatus;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class TaxPeriod.
 *
 * A quarter of a profile (design.md §8.3): its declaration status and deadlines, and the lock, which a submitted
 * declaration sets. Its liabilities aren't stored, TaxLiabilityCalculator computes them. Q4's declaration is the
 * annual one, with Додаток 1 for ЄСВ.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $year
 * @property int $quarter
 * @property bool $is_annual
 * @property DeclarationStatus $declaration_status
 * @property Carbon|null $declaration_submitted_at
 * @property Carbon $declaration_due_at
 * @property Carbon $tax_due_at
 * @property Carbon $esv_due_at
 * @property Carbon|null $locked_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(TaxPeriodFactory::class)]
class TaxPeriod extends BaseModel
{
    /** @use HasFactory<TaxPeriodFactory> */
    use HasFactory;

    use Audited;
    use BelongsToProfile;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'year',
        'quarter',
        'is_annual',
        'declaration_status',
        'declaration_submitted_at',
        'declaration_due_at',
        'tax_due_at',
        'esv_due_at',
        'notes',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'declaration_status' => 'none',
        'is_annual' => false,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'year' => 'int',
        'quarter' => 'int',
        'is_annual' => 'boolean',
        'declaration_status' => DeclarationStatus::class,
        'declaration_submitted_at' => 'datetime',
        'declaration_due_at' => 'date:Y-m-d',
        'tax_due_at' => 'date:Y-m-d',
        'esv_due_at' => 'date:Y-m-d',
        'locked_at' => 'datetime',
    ];

    /**
     * Determine if the quarter is locked by a submitted declaration.
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
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
