<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\AccountKind;
use PryTax\Models\Account;
use PryTax\Models\Profile;

/**
 * Class AccountFactory.
 *
 * Accounts of a new profile, unless one is given: platform accounts in USD by default.
 *
 * @extends Factory<Account>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'name' => 'Wise USD',
            'kind' => AccountKind::Platform,
            'currency' => 'USD',
            'holder_name' => fake()->name(),
            'bank_name' => 'Wise',
            'account_number' => fake()->numerify('##########'),
            'is_active' => true,
        ];
    }

    /**
     * A ФОП account in a Ukrainian bank.
     *
     * @param string $currency
     *
     * @return static
     */
    public function fopBank(string $currency = 'UAH'): static
    {
        return $this->state(fn () => [
            'name' => "ПриватБанк ФОП $currency",
            'kind' => AccountKind::FopBank,
            'currency' => $currency,
            'bank_name' => 'ПриватБанк',
            'iban' => 'UA' . fake()->numerify('###########################'),
        ]);
    }

    /**
     * A platform account in the currency.
     *
     * @param string $currency
     *
     * @return static
     */
    public function platform(string $currency = 'USD'): static
    {
        return $this->state(fn () => ['name' => "Wise $currency", 'kind' => AccountKind::Platform, 'currency' => $currency]);
    }

    /**
     * An account, which can't be chosen for new invoices or receipts.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
