<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Services\Invoices\InvoiceWriter;
use PryTax\Services\Invoices\PaymentPurposeRenderer;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PaymentPurposeRendererTest.
 *
 * The payment purpose fills the placeholders of its template in the invoice's language (design.md §4.1): dates as
 * `Oct 31, 2026` or `31 жовтня 2026`, the contract's subject as {services}, and warns of a reference, which SWIFT or
 * SEPA can't carry, in a foreign currency only.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class PaymentPurposeRendererTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Purposes in a currency with the warnings each gets.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function purposes(): array
    {
        $long = str_repeat('Payment for IT services ', 6);

        return [
            'SWIFT characters in dollars' => ["Payment for IT services by the invoice No. BL-1 from Oct 31, 2026 (O'Brien+Co)", 'USD', []],
            'longer than 140 characters in euros' => [$long, 'EUR', ['payment_purpose_too_long']],
            'a number sign in dollars' => ['Payment by the invoice № BL-1', 'USD', ['payment_purpose_charset']],
            'Cyrillic in euros' => ['Оплата за послуги', 'EUR', ['payment_purpose_charset']],
            'long Cyrillic in hryvnias' => [str_repeat('Оплата за послуги № 1 ', 8), 'UAH', []],
        ];
    }

    /**
     * Test that the template's placeholders are filled in the language: the number, the dates, the contract and the
     * parties' names in it.
     *
     * @return void
     */
    public function testFillsThePlaceholdersInTheLanguage(): void
    {
        $profile = $this->createProfile(null, ['full_name' => 'Мирчук Микола', 'full_name_en' => 'Myrchuk Mykola']);
        $invoice = $this->createDraft($profile, ['number' => 'BRIT-2026-10-31-2'], ['subject' => 'IT services', 'number' => '109-23']);
        $template = '{services} {invoice.number} {invoice.date} {period.start}–{period.end} {contract.name} {contract.number} '
            . '{contract.date} {profile.name}';

        $english = app(PaymentPurposeRenderer::class)->render($template, $this->inProfile($profile, fn () => app(PaymentPurposeRenderer::class)->values($invoice, 'en')));
        $ukrainian = app(PaymentPurposeRenderer::class)->render($template, $this->inProfile($profile, fn () => app(PaymentPurposeRenderer::class)->values($invoice, 'uk')));

        $this->assertSame('IT services BRIT-2026-10-31-2 Oct 31, 2026 Oct 1, 2026–Oct 31, 2026 Service Agreement 109-23 Apr 13, 2023 Myrchuk Mykola', $english);
        $this->assertSame('IT services BRIT-2026-10-31-2 31 жовтня 2026 1 жовтня 2026–31 жовтня 2026 Service Agreement 109-23 13 квітня 2023 Мирчук Микола', $ukrainian);
    }

    /**
     * Test that a contract without a subject gives the built-in word for services, in the language.
     *
     * @return void
     */
    public function testFallsBackToTheBuiltInServicesWithoutTheContractsSubject(): void
    {
        $profile = $this->createProfile();
        $invoice = $this->createDraft($profile);

        $values = $this->inProfile($profile, fn () => [
            'en' => app(PaymentPurposeRenderer::class)->values($invoice, 'en')['services'],
            'uk' => app(PaymentPurposeRenderer::class)->values($invoice, 'uk')['services'],
        ]);

        $this->assertSame(['en' => 'services', 'uk' => 'послуги'], $values);
    }

    /**
     * Test that the placeholders outside of the whitelist are found, each once.
     *
     * @return void
     */
    public function testFindsThePlaceholdersOutsideOfTheWhitelist(): void
    {
        $unknown = app(PaymentPurposeRenderer::class)->unknownPlaceholders('{invoice.number} {invoice.total} {iban} {invoice.total}');

        $this->assertSame(['invoice.total', 'iban'], $unknown);
    }

    /**
     * Test that a purpose in a foreign currency is warned of, when a SWIFT or SEPA reference can't carry it.
     *
     * @param string $purpose
     * @param string $currency
     * @param array<int, string> $warnings
     *
     * @return void
     */
    #[DataProvider('purposes')]
    public function testWarnsOfAPurposeASwiftReferenceCantCarry(string $purpose, string $currency, array $warnings): void
    {
        $this->assertSame($warnings, app(PaymentPurposeRenderer::class)->warnings($purpose, $currency));
    }

    /**
     * Create a draft invoice of the profile for October 2026, with a contract of the attributes.
     *
     * @param Profile $profile
     * @param array $data
     * @param array $contract
     *
     * @return Invoice
     */
    private function createDraft(Profile $profile, array $data = [], array $contract = []): Invoice
    {
        $account = $this->createAccount($profile);
        [$client, $model] = $this->createClientWithContract($profile, ['legal_name' => 'Bring IT LLC']);
        $this->inProfile($profile, fn () => $model->forceFill($contract)->save());

        return $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id,
            'contract_id' => $model->id,
            'receiving_account_id' => $account->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'items' => [['quantity' => '1']],
            ...$data,
        ]));
    }
}
