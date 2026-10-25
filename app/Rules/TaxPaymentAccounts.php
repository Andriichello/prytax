<?php

namespace PryTax\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;
use PryTax\Models\Account;

/**
 * Class TaxPaymentAccounts.
 *
 * Validates the accounts, which a profile pays its taxes from (`taxes.payment_account_ids`, design.md §8.4): distinct
 * accounts of the profile, each one, which taxes may be paid from.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Rules
 */
class TaxPaymentAccounts implements ValidationRule
{
    /**
     * TaxPaymentAccounts constructor.
     *
     * @param int|null $profileId
     */
    public function __construct(protected ?int $profileId) {}

    /**
     * Run the validation rule.
     *
     * @param string $attribute
     * @param mixed $value
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     *
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ids = is_array($value) ? $value : [];

        if (!array_is_list($ids) || collect($ids)->contains(fn (mixed $id) => !is_int($id) && !ctype_digit((string) $id))) {
            $fail(__('Choose the accounts from the list.'));

            return;
        }

        $ids = array_map('intval', $ids);

        if (count(array_unique($ids)) !== count($ids)) {
            $fail(__('An account is listed twice.'));

            return;
        }

        $payable = DB::table('accounts')
            ->where('profile_id', $this->profileId)
            ->whereIn('id', $ids)
            ->where(fn ($query) => Account::wherePaysTaxes($query))
            ->count();

        if ($payable !== count($ids)) {
            $fail(__('Taxes are paid from active accounts in UAH, or from active FOP accounts in another currency.'));
        }
    }
}
