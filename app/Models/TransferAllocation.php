<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Database\Factories\TransferAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class TransferAllocation.
 *
 * The part of a transfer, which carries a receipt's money, in the transfer's `currency_sent`. It is reached through
 * its transfer, which saves it.
 *
 * @property int $id
 * @property int $transfer_id
 * @property int $payment_id
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property AccountTransfer $transfer
 * @property Payment $payment
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(TransferAllocationFactory::class)]
class TransferAllocation extends BaseModel
{
    /** @use HasFactory<TransferAllocationFactory> */
    use HasFactory;

    use Audited;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'payment_id',
        'amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'transfer_id' => 'int',
        'payment_id' => 'int',
        'amount' => 'decimal:2',
    ];

    /**
     * Transfer of the allocation.
     *
     * @return BelongsTo
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(AccountTransfer::class, 'transfer_id');
    }

    /**
     * Receipt, whose money the transfer carries.
     *
     * @return BelongsTo
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
