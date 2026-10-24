<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Interfaces\HasFilesInterface;
use Core\Models\Interfaces\HasSettingsInterface;
use Core\Models\Traits\Audited;
use Core\Models\Traits\BelongsToOrganization;
use Core\Models\Traits\HasFiles;
use Core\Models\Traits\HasSequences;
use Core\Models\Traits\HasSettings;
use Core\Models\Traits\TracksCreator;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PryTax\Enums\AccountKind;
use PryTax\Enums\DeclarationStatus;
use PryTax\Enums\FxGainPolicy;
use PryTax\Enums\MoneyFormat;
use PryTax\Enums\NumberingDateSource;
use PryTax\Enums\NumberingPrefix;
use PryTax\Enums\NumberingReset;
use PryTax\Enums\NumberingScope;
use PryTax\Enums\NumberingStrategy;
use PryTax\Enums\TaxableBase;
use PryTax\Enums\TaxGrouping;
use PryTax\Enums\TaxIdKind;
use PryTax\Queries\ProfileQuery;
use PryTax\Services\Invoices\InvoiceSettingsResolver;
use PryTax\Services\Invoices\PaymentPurposeRenderer;

/**
 * Class Profile.
 *
 * One ФОП of an organization (design.md §0): its tax identity, its settings and the tenant of every domain record.
 * An accountant's practice has several, a sole proprietor one. `tax_id` is encrypted, so `tax_id_hash` (an HMAC of
 * it) keeps it unique within the organization. The profile owns the invoice counters (sequences) and the signature
 * image of the PDFs (the `signature` collection of its files).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $created_by_user_id
 * @property string $tax_group
 * @property string $full_name
 * @property string|null $full_name_en
 * @property string $tax_id
 * @property TaxIdKind $tax_id_kind
 * @property string $tax_id_hash
 * @property Carbon|null $registered_at
 * @property string|null $address
 * @property string|null $address_en
 * @property string|null $email
 * @property string|null $phone
 * @property string $default_currency
 * @property string $locale
 * @property bool $is_active
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read string $tax_id_masked
 * @property-read string $short_name
 * @property-read string $initials
 *
 * @method static ProfileQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(ProfileFactory::class)]
class Profile extends BaseModel implements HasFilesInterface, HasSettingsInterface
{
    use Audited;
    use BelongsToOrganization;

    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    use HasFiles;
    use HasSequences;
    use HasSettings;
    use SoftDeletes;
    use TracksCreator;

    /**
     * Collection of the files, which holds the signature image of the PDFs.
     *
     * @var string
     */
    public const string SIGNATURE_COLLECTION = 'signature';

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'full_name',
        'full_name_en',
        'tax_id',
        'tax_id_kind',
        'registered_at',
        'address',
        'address_en',
        'email',
        'phone',
        'default_currency',
        'locale',
        'is_active',
        'closed_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tax_group' => '3',
        'tax_id_kind' => 'rnokpp',
        'default_currency' => 'USD',
        'locale' => 'en',
        'is_active' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'tax_id_hash',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'tax_id_masked',
        'short_name',
        'initials',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tax_id' => 'encrypted',
        'tax_id_kind' => TaxIdKind::class,
        'registered_at' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'closed_at' => 'date:Y-m-d',
    ];

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saving(function (Profile $profile) {
            if ($profile->isDirty('tax_id') || $profile->tax_id_hash === null) {
                $profile->tax_id_hash = static::hashTaxId((string) $profile->tax_id);
            }
        });
    }

    /**
     * Get the HMAC of a tax id, which is unique within the organization, while the tax id itself is encrypted.
     *
     * @param string $taxId
     *
     * @return string
     */
    public static function hashTaxId(string $taxId): string
    {
        return hash_hmac('sha256', (string) preg_replace('/\s+/', '', $taxId), (string) config('app.key'));
    }

    /**
     * Settings of the profile, with their defaults (design.md §3, profile_settings).
     *
     * @return array<string, array>
     */
    public function settingDefinitions(): array
    {
        return [
            'invoice.numbering.prefix' => [
                'default' => NumberingPrefix::ClientAbbreviation->value,
                'rules' => [Rule::in(NumberingPrefix::values())],
            ],
            'invoice.numbering.strategy' => [
                'default' => NumberingStrategy::DateSequence->value,
                'rules' => [Rule::in(NumberingStrategy::values())],
            ],
            'invoice.numbering.scope' => [
                'default' => NumberingScope::PerClient->value,
                'rules' => [Rule::in(NumberingScope::values())],
            ],
            'invoice.numbering.reset' => [
                'default' => NumberingReset::Never->value,
                'rules' => [Rule::in(NumberingReset::values())],
            ],
            'invoice.numbering.date_source' => [
                'default' => NumberingDateSource::PeriodEnd->value,
                'rules' => [Rule::in(NumberingDateSource::values())],
            ],
            'invoice.footer_note' => [
                'default' => null,
                'rules' => ['nullable', 'string', 'max:1000'],
            ],
            // The ФОП's level of the invoice settings (design.md §4.1): null takes the built-in one; the language is
            // the document language of the profile itself (`locale`)
            'invoice.payment_purpose_template' => [
                'default' => null,
                'rules' => ['nullable', 'string', 'max:255', app(PaymentPurposeRenderer::class)->templateRule()],
            ],
            'invoice.act_note_enabled' => [
                'default' => null,
                'rules' => 'nullable|boolean',
                'cast' => 'bool',
            ],
            'invoice.act_note' => [
                'default' => null,
                'rules' => 'nullable|string|max:500',
            ],
            'invoice.show_contract' => [
                'default' => null,
                'rules' => 'nullable|boolean',
                'cast' => 'bool',
            ],
            'invoice.money_format' => [
                'default' => MoneyFormat::PlainDot->value,
                'rules' => [Rule::in(MoneyFormat::values())],
            ],
            'display.money_format' => [
                'default' => MoneyFormat::SpaceDot->value,
                'rules' => [Rule::in(MoneyFormat::values())],
            ],
            'income.taxable_base' => [
                'default' => TaxableBase::Invoiced->value,
                'rules' => [Rule::in(TaxableBase::values())],
            ],
            'income.fx_gain' => [
                'default' => FxGainPolicy::Ignore->value,
                'rules' => [Rule::in(FxGainPolicy::values())],
            ],
            'taxes.default_grouping' => [
                'default' => TaxGrouping::Payment->value,
                'rules' => [Rule::in(TaxGrouping::values())],
            ],
            'taxes.default_account_id' => [
                'default' => null,
                'rules' => [
                    'nullable',
                    'integer',
                    Rule::exists('accounts', 'id')
                        ->where('profile_id', $this->getKey())
                        ->where('kind', AccountKind::FopBank->value)
                        ->where('currency', 'UAH')
                        ->where('is_active', true),
                ],
                'cast' => 'int',
            ],
            // Null takes the tax rule's day (the 19th)
            'taxes.esv_due_day' => [
                'default' => null,
                'rules' => 'nullable|integer|min:1|max:28',
                'cast' => 'int',
            ],
            'compliance.platform_repatriation' => [
                'default' => 'quarter_end',
                'rules' => ['string', 'regex:/^(quarter_end|off|days:[1-9][0-9]{0,2})$/'],
            ],
            'alerts.deadline_days' => [
                'default' => 7,
                'rules' => 'integer|min:0|max:60',
                'cast' => 'int',
            ],
            // Days, after which a ready invoice without receipts is reported
            'alerts.ready_days' => [
                'default' => 14,
                'rules' => 'integer|min:1|max:365',
                'cast' => 'int',
            ],
        ];
    }

    /**
     * Accounts of the profile.
     *
     * @return HasMany
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Clients of the profile.
     *
     * @return HasMany
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Invoices of the profile.
     *
     * @return HasMany
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Receipts of the profile.
     *
     * @return HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Tax periods of the profile.
     *
     * @return HasMany
     */
    public function taxPeriods(): HasMany
    {
        return $this->hasMany(TaxPeriod::class);
    }

    /**
     * Notifications of the profile.
     *
     * @return HasMany
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Get the ФОП's name in the language of a document: the English one in English, when it is filled in.
     *
     * @param string $locale
     *
     * @return string
     */
    public function nameIn(string $locale): string
    {
        return $locale === 'uk' ? $this->full_name : ($this->full_name_en ?: $this->full_name);
    }

    /**
     * Get the ФОП's address in the language of a document: the English one in English, when it is filled in.
     *
     * @param string $locale
     *
     * @return string|null
     */
    public function addressIn(string $locale): ?string
    {
        return $locale === 'uk' ? $this->address : ($this->address_en ?: $this->address);
    }

    /**
     * The invoice settings, which the profile's clients take (design.md §4.1), each with the level it comes from:
     * `{locale: {value: 'en', source: 'profile', inherited: {…}}, …}`.
     *
     * @return Attribute
     */
    protected function effectiveInvoiceSettings(): Attribute
    {
        return Attribute::get(fn () => app(InvoiceSettingsResolver::class)->forProfile($this));
    }

    /**
     * The built-in texts of invoices in each language (the payment purpose template, the act note, the word for
     * services), which the forms of the invoice settings show for a setting, that inherits them.
     *
     * @return Attribute
     */
    protected function invoiceTexts(): Attribute
    {
        return Attribute::get(fn () => app(InvoiceSettingsResolver::class)->builtInTexts());
    }

    /**
     * Tax id, of which only the last four characters are shown, such as `••••4821`.
     *
     * @return Attribute
     */
    protected function taxIdMasked(): Attribute
    {
        return Attribute::get(fn () => '••••' . mb_substr((string) $this->tax_id, -4));
    }

    /**
     * Surname with initials, such as `Коваленко О. П.`.
     *
     * @return Attribute
     */
    protected function shortName(): Attribute
    {
        return Attribute::get(function () {
            $parts = preg_split('/\s+/u', trim((string) $this->full_name)) ?: [];
            $surname = array_shift($parts) ?? '';
            $initials = array_map(fn (string $part) => mb_substr($part, 0, 1) . '.', $parts);

            return trim($surname . ' ' . implode(' ', $initials));
        });
    }

    /**
     * Initials of the avatar, such as `КО`: the first letters of the surname and the name.
     *
     * @return Attribute
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function () {
            $parts = preg_split('/\s+/u', trim((string) $this->full_name)) ?: [];

            return Str::upper(implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), array_slice($parts, 0, 2))));
        });
    }

    /**
     * Number of the profile's notifications, which the signed-in user hasn't read.
     *
     * @return Attribute
     */
    protected function unreadAlertsCount(): Attribute
    {
        return Attribute::get(function () {
            $user = auth()->user();

            return $user ? Alert::query()
                ->withoutGlobalScope(Alert::PROFILE_SCOPE)
                ->where('profile_id', $this->getKey())
                ->unresolved()
                ->unreadBy($user)
                ->count() : 0;
        });
    }

    /**
     * Whether a quarter of the profile needs an уточнююча декларація.
     *
     * @return Attribute
     */
    protected function needsCorrection(): Attribute
    {
        return Attribute::get(fn () => TaxPeriod::query()
            ->withoutGlobalScope(TaxPeriod::PROFILE_SCOPE)
            ->where('profile_id', $this->getKey())
            ->where('declaration_status', DeclarationStatus::Corrected->value)
            ->exists());
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return ProfileQuery
     */
    public function newEloquentBuilder($query): ProfileQuery
    {
        return new ProfileQuery($query);
    }
}
