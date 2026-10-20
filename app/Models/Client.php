<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\TracksCreator;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Helpers\Decimal;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class Client.
 *
 * A counterparty of invoices (an accountant's ФОПs are always profiles, never clients). Clients are deactivated,
 * never deleted, so their invoice history stays. Under a client live its contracts, projects and charge types.
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $name_uk
 * @property string|null $legal_name_uk
 * @property string|null $country
 * @property string|null $currency
 * @property string|null $vat_number
 * @property array|null $address
 * @property string|null $tax_id
 * @property string|null $email
 * @property string|null $invoice_footer_note
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(ClientFactory::class)]
class Client extends BaseModel
{
    use BelongsToProfile;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'legal_name',
        'name_uk',
        'legal_name_uk',
        'country',
        'currency',
        'vat_number',
        'address',
        'tax_id',
        'email',
        'invoice_footer_note',
        'notes',
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
        'address' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Contracts of the client.
     *
     * @return HasMany
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Projects of the client.
     *
     * @return HasMany
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Charge types of the client.
     *
     * @return HasMany
     */
    public function chargeTypes(): HasMany
    {
        return $this->hasMany(ChargeType::class);
    }

    /**
     * Invoices of the client.
     *
     * @return HasMany
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * What the client owes on its open invoices, per currency, with the overdue part of it.
     *
     * @return Attribute
     */
    protected function outstanding(): Attribute
    {
        return Attribute::get(function () {
            $today = now()->startOfDay();

            return $this->invoices()->open()->withPaidAmount()->get()
                ->groupBy('currency')
                ->map(fn ($invoices, string $currency) => [
                    'currency' => $currency,
                    'amount' => Decimal::money(Decimal::sum($invoices->map(
                        fn (Invoice $invoice) => Decimal::max(Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount)), 0)
                    ))),
                    'overdue' => Decimal::money(Decimal::sum($invoices
                        ->filter(fn (Invoice $invoice) => $invoice->due_at?->lt($today))
                        ->map(fn (Invoice $invoice) => Decimal::max(Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount)), 0)))),
                ])
                ->values()
                ->all();
        });
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
