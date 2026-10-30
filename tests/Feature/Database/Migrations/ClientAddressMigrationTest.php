<?php

namespace Tests\Feature\Database\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PryTax\Models\Client;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ClientAddressMigrationTest.
 *
 * The migration of the clients' addresses (2026-10-30): an address of parts becomes the text its invoices printed, line
 * by line: its lines, then the city, the state and the postal code, then the country. One already a text stays, and
 * one without anything in it goes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Database\Migrations
 */
class ClientAddressMigrationTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the parts become the text, a text stays, and an empty address goes.
     *
     * @return void
     */
    public function testTurnsAClientsAddressPartsIntoTheTextItsInvoicesPrinted(): void
    {
        $profile = $this->createProfile();
        $parts = $this->clientWith($profile, ['lines' => ['500 Market St', ' ', 'Suite 200'], 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94105'], 'US');
        $text = $this->clientWith($profile, ['text' => "Unit 5\nDublin"], 'IE');
        $empty = $this->clientWith($profile, ['lines' => [''], 'city' => null], null);

        $migration = require database_path('migrations/2026_10_30_140422_convert_client_addresses_to_text.php');
        $migration->up();

        $this->assertSame(['text' => "500 Market St\nSuite 200\nSan Francisco CA 94105\nUS"], $this->addressOf($parts));
        $this->assertSame(['text' => "Unit 5\nDublin"], $this->addressOf($text));
        $this->assertNull($this->addressOf($empty));
    }

    /**
     * Create a client of the profile, whose address and country are stored as they were.
     *
     * @param Profile $profile
     * @param array $address
     * @param string|null $country
     *
     * @return int
     */
    private function clientWith(Profile $profile, array $address, ?string $country): int
    {
        $id = $this->inProfile($profile, fn () => Client::factory()->create(['profile_id' => $profile->id])->id);
        DB::table('clients')->where('id', $id)->update(['address' => json_encode($address), 'country' => $country]);

        return $id;
    }

    /**
     * Read the client's stored address.
     *
     * @param int $id
     *
     * @return array|null
     */
    private function addressOf(int $id): ?array
    {
        $address = DB::table('clients')->where('id', $id)->value('address');

        return $address === null ? null : json_decode($address, true);
    }
}
