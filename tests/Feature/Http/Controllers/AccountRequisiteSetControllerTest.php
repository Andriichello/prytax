<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountControllerTest.
 *
 * An account's requisites in invoices (invoice.md §4): the order they print in and which of them print, each named
 * by its field or, a detail, by its label; refused with 422, when an entry names neither, both or an unknown field.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class AccountControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an accountant saves the order of the requisites in invoices, and which of them print.
     *
     * @return void
     */
    public function testSavesTheOrderOfTheRequisitesInInvoices(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $account = $this->createAccount($profile, ['details' => [['label' => 'Routing (ABA)', 'value' => '022200101']]]);
        $order = [
            ['label' => 'Routing (ABA)', 'shown' => true],
            ['field' => 'iban', 'shown' => false],
            ['field' => 'holder_name', 'shown' => true],
        ];

        $response = $this->patchJson($this->profilePath($profile, "accounts/{$account->id}"), ['invoice_requisites' => $order]);

        $response->assertOk()->assertJsonPath('data.invoice_requisites', $order);
        $this->assertSame($order, $account->refresh()->invoice_requisites);
    }

    /**
     * Test that an entry of the order, which names neither a field nor a label, both, an unknown field, or leaves out
     * whether it prints, is refused (422), and nothing is saved.
     *
     * @param array $entry
     * @param string $key
     * @param string $message
     *
     * @return void
     */
    #[DataProvider('wrongEntries')]
    public function testReturns422ForAWrongEntryOfTheOrder(array $entry, string $key, string $message): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);

        $response = $this->patchJson($this->profilePath($profile, "accounts/{$account->id}"), ['invoice_requisites' => [$entry]]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);
        $this->assertNull($account->refresh()->invoice_requisites);
    }

    /**
     * Get the wrong entries, with the key and the message of the refusal.
     *
     * @return array<string, array{array, string, string}>
     */
    public static function wrongEntries(): array
    {
        return [
            'neither' => [['shown' => true], 'invoice_requisites.0.field', 'The invoice_requisites.0.field field is required when invoice_requisites.0.label is not present.'],
            'both' => [['field' => 'iban', 'label' => 'IBAN', 'shown' => true], 'invoice_requisites.0.field', 'The invoice_requisites.0.field field prohibits invoice_requisites.0.label from being present.'],
            'an unknown field' => [['field' => 'name', 'shown' => true], 'invoice_requisites.0.field', 'The selected invoice_requisites.0.field is invalid.'],
            'without shown' => [['field' => 'iban'], 'invoice_requisites.0.shown', 'The invoice_requisites.0.shown field is required.'],
        ];
    }
}
