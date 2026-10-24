<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\AuditLog;
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

    /**
     * Test that the ФОП's invoice settings are saved, and a payment purpose with a placeholder outside of the
     * whitelist is refused with 422.
     *
     * @return void
     */
    public function testSavesTheInvoiceSettingsButNotAnUnknownPlaceholder(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $path = $this->profilePath($profile, 'settings');

        $saved = $this->patchJson($path, ['invoice' => ['act_note_enabled' => false, 'show_contract' => false, 'payment_purpose_template' => 'For {services}']]);
        $refused = $this->patchJson($path, ['invoice' => ['payment_purpose_template' => 'For {services} {invoice.total}']]);

        $saved->assertOk()
            ->assertJsonPath('data.invoice.act_note_enabled', false)
            ->assertJsonPath('data.invoice.show_contract', false)
            ->assertJsonPath('data.invoice.payment_purpose_template', 'For {services}');
        $refused->assertUnprocessable()->assertJsonValidationErrors([
            'invoice.payment_purpose_template' => 'The payment purpose has unknown placeholders: {invoice.total}.',
        ]);
    }

    /**
     * Test that accountants change the settings, while viewers can't (403).
     *
     * @return void
     */
    public function testLetsAccountantsButNotViewersChangeTheSettings(): void
    {
        $profile = $this->createProfile();

        $this->actAs($profile, 'viewer');
        $this->patchJson($this->profilePath($profile, 'settings'), ['alerts' => ['deadline_days' => 10]])->assertForbidden();
        $this->actAs($profile, 'accountant');
        $this->patchJson($this->profilePath($profile, 'settings'), ['alerts' => ['deadline_days' => 10]])
            ->assertOk()
            ->assertJsonPath('data.alerts.deadline_days', 10);
    }

    /**
     * Test that a change of the settings, which change tax figures, is recorded in the audit log with who made it.
     *
     * @return void
     */
    public function testRecordsTheChangedSettingsInTheAuditLog(): void
    {
        $profile = $this->createProfile();
        $user = $this->actAs($profile, 'accountant');

        $this->patchJson($this->profilePath($profile, 'settings'), ['income' => ['taxable_base' => 'received']])->assertOk();

        $entry = AuditLog::query()->where('event', 'settings_updated')->firstOrFail();
        $this->assertSame($profile->id, (int) $entry->subject_id);
        $this->assertSame($user->id, (int) $entry->causer_id);
        $this->assertSame(['income.taxable_base' => 'invoiced'], $entry->changes['old']);
        $this->assertSame(['income.taxable_base' => 'received'], $entry->changes['new']);
    }
}
