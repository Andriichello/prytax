<?php

namespace PryTax\Http\Requests\Profiles;

use Closure;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxIdKind;
use PryTax\Models\Profile;

/**
 * Trait ProfileRules.
 *
 * Rules of a profile's fields, which its store and update requests share. The tax id is РНОКПП (10 digits), or a
 * passport (two Cyrillic letters and six digits, or the nine digits of an ID card), unique within the organization
 * by its HMAC, since the column is encrypted.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Profiles
 */
trait ProfileRules
{
    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence `required` or `sometimes`.
     * @param int|null $ignoreId The profile, which is updated.
     *
     * @return array
     */
    protected function profileRules(string $presence, ?int $ignoreId = null): array
    {
        return [
            'full_name' => [$presence, 'string', 'max:255'],
            'full_name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => [$presence, 'string', 'max:20', $this->taxIdFormat(), $this->uniqueTaxId($ignoreId)],
            'tax_id_kind' => ['sometimes', Rule::enum(TaxIdKind::class)],
            'registered_at' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address_en' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'default_currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'locale' => ['sometimes', Rule::in(['en', 'uk'])],
            'is_active' => ['sometimes', 'boolean'],
            'closed_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * Normalize the tax id (without spaces) and the currency (upper case).
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = [];

        if (is_string($this->input('tax_id'))) {
            $overrides['tax_id'] = mb_strtoupper((string) preg_replace('/\s+/', '', $this->input('tax_id')));
        }

        if (is_string($this->input('default_currency'))) {
            $overrides['default_currency'] = strtoupper($this->input('default_currency'));
        }

        return $overrides;
    }

    /**
     * Rule: the tax id has the format of its kind.
     *
     * @return Closure
     */
    protected function taxIdFormat(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $kind = $this->input('tax_id_kind', TaxIdKind::Rnokpp->value);

            $valid = $kind === TaxIdKind::Passport->value
                ? preg_match('/^([А-ЯІЇЄҐ]{2}\d{6}|\d{9})$/u', (string) $value)
                : preg_match('/^\d{10}$/', (string) $value);

            if (!$valid) {
                $fail(__($kind === TaxIdKind::Passport->value
                    ? 'The passport is two letters and six digits, or the nine digits of an ID card.'
                    : 'РНОКПП is 10 digits.'));
            }
        };
    }

    /**
     * Rule: no other profile of the organization has the tax id.
     *
     * @param int|null $ignoreId
     *
     * @return Closure
     */
    protected function uniqueTaxId(?int $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId) {
            $taken = Profile::query()
                ->withTrashed()
                ->where('tax_id_hash', Profile::hashTaxId((string) $value))
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail(__('Another ФОП of the organization has this tax id.'));
            }
        };
    }
}
