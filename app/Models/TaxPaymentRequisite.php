<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\TaxPaymentRequisiteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use PryTax\Enums\TaxType;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class TaxPaymentRequisite.
 *
 * Budget requisites of a tax from a date, for the copy-ready block of a tax payment: the IBAN, the recipient and its
 * code, and the purpose, whose `{tax_id}`, `{period}` and `{full_name}` are filled in.
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $created_by_user_id
 * @property TaxType $tax_type
 * @property string $iban
 * @property string $recipient_name
 * @property string $recipient_code
 * @property string $purpose_template
 * @property Carbon $effective_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(TaxPaymentRequisiteFactory::class)]
class TaxPaymentRequisite extends BaseModel
{
    /** @use HasFactory<TaxPaymentRequisiteFactory> */
    use HasFactory;

    use Audited;
    use TracksCreator;
    use BelongsToProfile;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tax_type',
        'iban',
        'recipient_name',
        'recipient_code',
        'purpose_template',
        'effective_from',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tax_type' => TaxType::class,
        'effective_from' => 'date:Y-m-d',
    ];

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
