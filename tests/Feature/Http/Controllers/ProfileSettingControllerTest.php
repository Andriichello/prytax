<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ProfileSettingControllerTest.
 *
 * Settings of a profile, with their defaults: the money formats of the app and of invoices, and the account taxes
 * are paid from, which must be an active UAH ФОП account of the profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class ProfileSettingControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the settings default to `1 234.99` in the app and `2851.99` on invoices.
     *
     * @return void
     */
    public function testShowsTheDefaultMoneyFormats(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');

        $this->getJson($this->profilePath($profile, 'settings'))
            ->assertOk()
            ->assertJsonPath('data.display.money_format', 'space_dot')
            ->assertJsonPath('data.invoice.money_format', 'plain_dot')
            ->assertJsonPath('data.income.taxable_base', 'invoiced')
            ->assertJsonPath('data.alerts.deadline_days', 7);
    }

    /**
     * Test that the tax account must be a UAH ФОП account, and an unknown money format is refused.
     *
     * @return void
     */
    public function testReturns422ForAWrongTaxAccountOrMoneyFormat(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);

        $this->patchJson($this->profilePath($profile, 'settings'), [
            'taxes' => ['default_account_id' => $wise->id],
            'display' => ['money_format' => 'emoji'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'taxes.default_account_id' => 'The selected taxes.default account id is invalid.',
            'display.money_format' => 'The selected display.money format is invalid.',
        ]);

        $this->patchJson($this->profilePath($profile, 'settings'), ['taxes' => ['default_account_id' => $bank->id]])
            ->assertOk()
            ->assertJsonPath('data.taxes.default_account_id', $bank->id);
    }
}
