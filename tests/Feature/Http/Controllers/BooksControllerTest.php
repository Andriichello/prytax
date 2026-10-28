<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class BooksControllerTest.
 *
 * Книги обліку's endpoints (design.md §16.7): the income book and the taxes of a year, which a viewer may read too,
 * and each of them as a PDF.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class BooksControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a viewer reads both books of a year and downloads each as a PDF.
     *
     * @return void
     */
    public function testServesBothBooksAndTheirPdfs(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => Payment::factory()->create(['profile_id' => $profile->id, 'received_at' => '2026-10-05']));
        $this->actAs($profile, 'viewer');

        $this->getJson($this->profilePath($profile, 'books/2026/income'))
            ->assertOk()
            ->assertJsonPath('data.ytd', '41000.00')
            ->assertJsonPath('data.quarters.0.months.0.receipts.0.amount_uah', '41000.00');
        $this->getJson($this->profilePath($profile, 'books/2026/taxes'))
            ->assertOk()
            ->assertJsonPath('data.cumulative.0.to_quarter', 4)
            ->assertJsonPath('data.by_tax.1.tax_type', 'single_tax');

        foreach (['income', 'taxes'] as $book) {
            $response = $this->get($this->profilePath($profile, "books/2026/$book/export?format=pdf"));

            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }
}
