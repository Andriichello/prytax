<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\AccountRequisiteSetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class AccountRequisiteSet.
 *
 * A set of an account's requisites, which an invoice prints as a «Payment details (…)» section (invoice.md §4): one
 * per way of paying into the account, such as SEPA and SWIFT of Monobank's EUR account, each with its own receiver,
 * IBAN and code (SWIFT and BIC are one). Its name is what the invoice prints in the parentheses; `labels` what its
 * fields print as («Beneficiary name» for the receiver), else the invoice's language's; `invoice_requisites` the order
 * of its requisites and which of them print (`InvoiceRequisites`). One active set of an account is its default, which a new
 * invoice prints; a set switched off isn't offered to new invoices. The IBAN and the account number are encrypted.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $account_id
 * @property int|null $created_by_user_id
 * @property string|null $name
 * @property string|null $holder_name
 * @property string|null $bank_name
 * @property string|null $iban
 * @property string|null $account_number
 * @property string|null $swift
 * @property array<int, array{label: string, value: string}>|null $details
 * @property array<string, string>|null $labels
 * @property array<int, array{field?: string|null, label?: string|null, shown: bool}>|null $invoice_requisites
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Account $account
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(AccountRequisiteSetFactory::class)]
class AccountRequisiteSet extends BaseModel
{
    use Audited;
    use BelongsToProfile;

    /** @use HasFactory<AccountRequisiteSetFactory> */
    use HasFactory;

    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'holder_name',
        'bank_name',
        'iban',
        'account_number',
        'swift',
        'details',
        'labels',
        'invoice_requisites',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'iban' => 'encrypted',
        'account_number' => 'encrypted',
        'details' => 'array',
        'labels' => 'array',
        'invoice_requisites' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'int',
    ];

    /**
     * Account of the set.
     *
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Sections of invoices, which print the set.
     *
     * @return HasMany
     */
    public function paymentSections(): HasMany
    {
        return $this->hasMany(InvoicePaymentSection::class, 'requisite_set_id');
    }

    /**
     * Get the set's requisites, as `InvoiceRequisites::ordered()` and an invoice's snapshot take them.
     *
     * @return array{holder_name: string|null, bank_name: string|null, iban: string|null, account_number: string|null,
     *     swift: string|null, details: array|null, labels: array|null, invoice_requisites: array|null}
     */
    public function requisites(): array
    {
        return $this->only(['holder_name', 'bank_name', 'iban', 'account_number', 'swift', 'details', 'labels', 'invoice_requisites']);
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
