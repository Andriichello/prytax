<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Enums\InvoiceLocale;
use PryTax\Helpers\Decimal;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;
use PryTax\Services\Invoices\InvoiceSettingsResolver;

/**
 * Class Client.
 *
 * A counterparty of invoices (an accountant's ФОПs are always profiles, never clients). Clients are deactivated,
 * never deleted, so their invoice history stays. Its abbreviation, unique within the profile, prefixes its invoice
 * numbers (design.md §5). Under a client live its contracts, projects and charge types. Its invoice settings (language,
 * payment purpose, act note, contract section) are null to take the profile's (design.md §4.1).
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string $abbreviation
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
 * @property InvoiceLocale|null $invoice_locale
 * @property string|null $payment_purpose_template
 * @property bool|null $act_note_enabled
 * @property string|null $act_note
 * @property bool|null $show_contract
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
    use Audited;
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
        'abbreviation',
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
        'invoice_locale',
        'payment_purpose_template',
        'act_note_enabled',
        'act_note',
        'show_contract',
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
        'invoice_locale' => InvoiceLocale::class,
        'act_note_enabled' => 'boolean',
        'show_contract' => 'boolean',
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
     * Get the client's legal name in the language of a document: the Ukrainian one in Ukrainian, when it is filled
     * in, and the name, when the legal name isn't.
     *
     * @param string $locale
     *
     * @return string
     */
    public function legalNameIn(string $locale): string
    {
        $ukrainian = $locale === 'uk' ? ($this->legal_name_uk ?: $this->name_uk) : null;

        return $ukrainian ?: ($this->legal_name ?: $this->name);
    }

    /**
     * The document settings, which the client's new invoices take (design.md §4.1), each with the level it comes
     * from: `{locale: {value: 'en', source: 'profile'}, …}`.
     *
     * @return Attribute
     */
    protected function effectiveSettings(): Attribute
    {
        return Attribute::get(fn () => app(InvoiceSettingsResolver::class)->forClient($this));
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
