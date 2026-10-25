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
            ->assertJsonPath('data.taxes.payment_account_ids', [])
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
     * Test that the ФОП's invoice settings are saved and shown on the profile with the built-in ones they replace,
     * whose texts it gives in each language, and a payment purpose with a placeholder outside of the whitelist is
     * refused with 422.
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
        $profileShown = $this->getJson("/api/profiles/{$profile->id}?append=effective_invoice_settings,invoice_texts");

        $saved->assertOk()
            ->assertJsonPath('data.invoice.act_note_enabled', false)
            ->assertJsonPath('data.invoice.show_contract', false)
            ->assertJsonPath('data.invoice.payment_purpose_template', 'For {services}');
        $refused->assertUnprocessable()->assertJsonValidationErrors([
            'invoice.payment_purpose_template' => 'The payment purpose has unknown placeholders: {invoice.total}.',
        ]);
        $profileShown->assertOk()
            ->assertJsonPath('data.effective_invoice_settings.act_note_enabled.source', 'profile')
            ->assertJsonPath('data.effective_invoice_settings.act_note_enabled.inherited', ['value' => true, 'source' => 'default'])
            ->assertJsonPath('data.invoice_texts.uk.services', 'послуги')
            ->assertJsonPath('data.invoice_texts.en.payment_purpose', 'Payment for {services} by the invoice No. {invoice.number} from {invoice.date}');
    }

    /**
     * Test that the ФОП's style and both notes are saved, while a note switched on without its text is refused with
     * 422.
     *
     * @return void
     */
    public function testSavesTheStyleAndNotesButNotANoteWithoutItsText(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $path = $this->profilePath($profile, 'settings');

        $saved = $this->patchJson($path, ['invoice' => [
            'style' => 'andriichello_sakura',
            'payment_note_enabled' => true,
            'payment_note' => 'Quote the number.',
            'other_note_enabled' => true,
            'other_note' => 'Thank you!',
        ]]);
        $refused = $this->patchJson($path, ['invoice' => ['other_note_enabled' => true, 'other_note' => null]]);

        $saved->assertOk()
            ->assertJsonPath('data.invoice.style', 'andriichello_sakura')
            ->assertJsonPath('data.invoice.payment_note', 'Quote the number.')
            ->assertJsonPath('data.invoice.other_note_enabled', true)
            ->assertJsonPath('data.invoice.other_note', 'Thank you!');
        $refused->assertUnprocessable()->assertJsonValidationErrors(['invoice.other_note' => 'Enter the note text.']);
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

        $this->patchJson($this->profilePath($profile, 'settings'), ['income' => ['fx_gain' => 'include']])->assertOk();

        $entry = AuditLog::query()->where('event', 'settings_updated')->firstOrFail();
        $this->assertSame($profile->id, (int) $entry->subject_id);
        $this->assertSame($user->id, (int) $entry->causer_id);
        $this->assertSame(['income.fx_gain' => 'ignore'], $entry->changes['old']);
        $this->assertSame(['income.fx_gain' => 'include'], $entry->changes['new']);
    }

    /**
     * Test that taxes are paid from UAH accounts and from ФОП accounts in another currency, with a primary one.
     *
     * @return void
     */
    public function testStoresTheAccountsTaxesArePaidFrom(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $privatUah = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);
        $monoUsd = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'USD', 'name' => 'Monobank ФОП']);

        $this->patchJson($this->profilePath($profile, 'settings'), [
            'taxes' => ['payment_account_ids' => [$privatUah->id, $monoUsd->id], 'default_account_id' => $monoUsd->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.taxes.payment_account_ids', [$privatUah->id, $monoUsd->id])
            ->assertJsonPath('data.taxes.default_account_id', $monoUsd->id);
    }

    /**
     * Test that a platform, an inactive account and a repeated one aren't accounts to pay taxes from.
     *
     * @return void
     */
    public function testReturns422ForAccountsTaxesArentPaidFrom(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $payoneer = $this->createAccount($profile, ['kind' => 'platform', 'currency' => 'USD', 'name' => 'Payoneer USD']);
        $closed = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'Закритий', 'is_active' => false]);
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);

        foreach ([[$payoneer->id], [$closed->id], [$bank->id, $bank->id]] as $accounts) {
            $this->patchJson($this->profilePath($profile, 'settings'), ['taxes' => ['payment_account_ids' => $accounts]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('taxes.payment_account_ids');
        }
    }
}
