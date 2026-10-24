<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Invoice;
use PryTax\Services\Invoices\InvoiceSettingsResolver;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceSettingsResolverTest.
 *
 * The document settings of an invoice cascade (design.md §4.1): each is the invoice's own, else the client's, else
 * the profile's, else the built-in one, which is in the resolved language; each tells the level it comes from, and the
 * value the levels below give it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class InvoiceSettingsResolverTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that, with nothing set, the settings are the built-in ones in the profile's document language.
     *
     * @return void
     */
    public function testFallsBackToTheBuiltInSettingsInTheProfilesLanguage(): void
    {
        $profile = $this->createProfile(null, ['locale' => 'uk']);
        [$client] = $this->createClientWithContract($profile);

        $settings = $this->inProfile($profile, fn () => $this->levels(app(InvoiceSettingsResolver::class)->forClient($client)));

        $this->assertSame([
            'locale' => ['value' => 'uk', 'source' => 'profile'],
            'payment_purpose_template' => [
                'value' => 'Оплата за {services} згідно з інвойсом № {invoice.number} від {invoice.date}',
                'source' => 'default',
            ],
            'act_note_enabled' => ['value' => true, 'source' => 'default'],
            'act_note' => [
                'value' => 'Оплата цього інвойсу підтверджує належне надання послуг і є актом приймання-передачі наданих послуг. Додаткові підписи не потрібні.',
                'source' => 'default',
            ],
            'show_contract' => ['value' => true, 'source' => 'default'],
        ], $settings);
    }

    /**
     * Test that the profile's settings replace the built-in ones.
     *
     * @return void
     */
    public function testTakesTheProfilesSettingsOverTheBuiltInOnes(): void
    {
        $profile = $this->createProfile();
        [$client] = $this->createClientWithContract($profile);
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice' => [
            'payment_purpose_template' => 'Invoice {invoice.number}',
            'act_note_enabled' => false,
            'act_note' => 'Paid means accepted.',
            'show_contract' => false,
        ]]));

        $settings = $this->inProfile($profile, fn () => $this->levels(app(InvoiceSettingsResolver::class)->forClient($client->refresh())));

        $this->assertSame([
            'locale' => ['value' => 'en', 'source' => 'profile'],
            'payment_purpose_template' => ['value' => 'Invoice {invoice.number}', 'source' => 'profile'],
            'act_note_enabled' => ['value' => false, 'source' => 'profile'],
            'act_note' => ['value' => 'Paid means accepted.', 'source' => 'profile'],
            'show_contract' => ['value' => false, 'source' => 'profile'],
        ], $settings);
    }

    /**
     * Test that the client's settings replace the profile's, and that the built-in texts follow the client's language.
     *
     * @return void
     */
    public function testTakesTheClientsSettingsOverTheProfiles(): void
    {
        $profile = $this->createProfile();
        [$client] = $this->createClientWithContract($profile, [
            'invoice_locale' => 'uk',
            'act_note_enabled' => true,
            'show_contract' => true,
        ]);
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice' => [
            'act_note_enabled' => false,
            'show_contract' => false,
        ]]));

        $settings = $this->inProfile($profile, fn () => $this->levels(app(InvoiceSettingsResolver::class)->forClient($client->refresh())));

        $this->assertSame(['value' => 'uk', 'source' => 'client'], $settings['locale']);
        $this->assertSame('default', $settings['payment_purpose_template']['source']);
        $this->assertStringStartsWith('Оплата за {services}', $settings['payment_purpose_template']['value']);
        $this->assertSame(['value' => true, 'source' => 'client'], $settings['act_note_enabled']);
        $this->assertSame(['value' => true, 'source' => 'client'], $settings['show_contract']);
    }

    /**
     * Test that the invoice's own settings replace the client's.
     *
     * @return void
     */
    public function testTakesTheInvoicesSettingsOverTheClients(): void
    {
        $profile = $this->createProfile();
        [$client] = $this->createClientWithContract($profile, [
            'invoice_locale' => 'uk',
            'payment_purpose_template' => 'Client {invoice.number}',
            'act_note' => 'Client note.',
            'show_contract' => true,
        ]);
        $invoice = new Invoice;
        $invoice->forceFill([
            'client_id' => $client->id,
            'locale' => 'en',
            'payment_purpose_template' => 'Invoice {invoice.number}',
            'act_note_enabled' => false,
            'act_note' => 'Invoice note.',
            'show_contract' => false,
        ]);

        $settings = $this->inProfile($profile, fn () => $this->levels(app(InvoiceSettingsResolver::class)->forInvoice($invoice)));

        $this->assertSame([
            'locale' => ['value' => 'en', 'source' => 'invoice'],
            'payment_purpose_template' => ['value' => 'Invoice {invoice.number}', 'source' => 'invoice'],
            'act_note_enabled' => ['value' => false, 'source' => 'invoice'],
            'act_note' => ['value' => 'Invoice note.', 'source' => 'invoice'],
            'show_contract' => ['value' => false, 'source' => 'invoice'],
        ], $settings);
    }

    /**
     * Test that each setting tells the value, which the levels below the client's give it: the profile's, or the
     * built-in one in the client's language.
     *
     * @return void
     */
    public function testGivesEachSettingTheValueTheLevelsBelowGiveIt(): void
    {
        $profile = $this->createProfile();
        [$client] = $this->createClientWithContract($profile, ['invoice_locale' => 'uk', 'act_note_enabled' => false]);
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice' => ['act_note_enabled' => true]]));

        $settings = $this->inProfile($profile, fn () => app(InvoiceSettingsResolver::class)->forClient($client->refresh()));

        $this->assertSame(['value' => 'uk', 'source' => 'client', 'inherited' => ['value' => 'en', 'source' => 'profile']], $settings['locale']);
        $this->assertSame(['value' => false, 'source' => 'client', 'inherited' => ['value' => true, 'source' => 'profile']], $settings['act_note_enabled']);
        $this->assertSame(['value' => true, 'source' => 'default', 'inherited' => ['value' => true, 'source' => 'default']], $settings['show_contract']);
        $this->assertStringStartsWith('Оплата за {services}', $settings['payment_purpose_template']['inherited']['value']);
    }

    /**
     * Get the resolved settings without the values they inherit.
     *
     * @param array<string, array{value: mixed, source: string, inherited: array}> $settings
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    private function levels(array $settings): array
    {
        return array_map(fn (array $setting) => ['value' => $setting['value'], 'source' => $setting['source']], $settings);
    }
}
