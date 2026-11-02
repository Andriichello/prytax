<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Account;
use PryTax\Models\AccountRequisiteSet;

/**
 * Class AccountRequisiteSetFactory.
 *
 * Sets of requisites of a new account, unless one is given: the default set of a Wise USD account, with a receiver,
 * the bank and an account number.
 *
 * @extends Factory<AccountRequisiteSet>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class AccountRequisiteSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'profile_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()->findOrFail($attributes['account_id'])->profile_id,
            'name' => null,
            'holder_name' => fake()->name(),
            'bank_name' => 'Wise',
            'account_number' => fake()->numerify('##########'),
            'is_default' => true,
            'is_active' => true,
        ];
    }

    /**
     * A set for SEPA transfers: a receiver, a foreign IBAN and its code, labelled «BIC».
     *
     * @return static
     */
    public function sepa(): static
    {
        return $this->state(fn () => [
            'name' => 'SEPA',
            'bank_name' => null,
            'account_number' => null,
            'iban' => 'GB' . fake()->numerify('####################'),
            'swift' => 'CLJUGB21',
            'labels' => ['swift' => 'BIC'],
        ]);
    }

    /**
     * A set for SWIFT transfers: a receiver, a Ukrainian IBAN and a SWIFT code.
     *
     * @return static
     */
    public function swift(): static
    {
        return $this->state(fn () => [
            'name' => 'SWIFT',
            'bank_name' => null,
            'account_number' => null,
            'iban' => 'UA' . fake()->numerify('###########################'),
            'swift' => 'UNJSUAUKXXX',
        ]);
    }

    /**
     * A set, which isn't the account's default.
     *
     * @return static
     */
    public function other(): static
    {
        return $this->state(fn () => ['is_default' => false]);
    }

    /**
     * A set, which isn't offered to new invoices.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'is_default' => false]);
    }
}
