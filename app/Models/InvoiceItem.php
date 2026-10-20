<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Interfaces\LockableInterface;
use Core\Models\Traits\Audited;
use Core\Models\Traits\LockedByParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Enums\ItemUnit;

/**
 * Class InvoiceItem.
 *
 * A line of an invoice, such as `PROTO · Billable · "IT services …" · 86.50 h × 45.00 = 3 892.50`. The project's
 * code and the charge type's name are copied as text, so the item survives later renames. It can't change while its
 * invoice is locked.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property int|null $project_id
 * @property string|null $project_code
 * @property int|null $charge_type_id
 * @property string|null $charge_type
 * @property string $description
 * @property ItemUnit $unit
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Invoice $invoice
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class InvoiceItem extends BaseModel
{
    use Audited;
    use LockedByParent;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'position',
        'project_id',
        'project_code',
        'charge_type_id',
        'charge_type',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'position' => 'int',
        'project_id' => 'int',
        'charge_type_id' => 'int',
        'unit' => ItemUnit::class,
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    /**
     * Invoice of the item.
     *
     * @return BelongsTo
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Project of the item.
     *
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Charge type of the item.
     *
     * @return BelongsTo
     */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    /**
     * The record, which locks the item: its invoice, found without the profile's scope, so the lock holds in code,
     * which runs outside of a profile, such as a console command.
     *
     * @return (Model&LockableInterface)|null
     */
    public function lockingParent(): (Model&LockableInterface)|null
    {
        if ($this->relationLoaded('invoice') && $this->getRelation('invoice') !== null) {
            return $this->getRelation('invoice');
        }

        return Invoice::query()->withoutGlobalScopes()->find($this->getAttribute('invoice_id'));
    }
}
