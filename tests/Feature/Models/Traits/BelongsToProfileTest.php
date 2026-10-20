<?php

namespace Tests\Feature\Models\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PryTax\Models\Account;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class BelongsToProfileTest.
 *
 * The scope of a profile's records (design.md §0): queries see the current profile's records only, and none without
 * a current profile, and records can't be saved into another profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Models\Traits
 */
class BelongsToProfileTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that queries see the current profile's records, and nothing without a current profile.
     *
     * @return void
     */
    public function testSeesTheCurrentProfilesRecordsAndNoneWithoutOne(): void
    {
        $first = $this->createProfile();
        $second = $this->createProfile($first->organization);
        $mine = $this->createAccount($first);
        $this->createAccount($second);

        $this->assertSame([$mine->id], $this->inProfile($first, fn () => Account::query()->pluck('id')->all()));
        $this->assertSame(0, Account::query()->count());
    }

    /**
     * Test that a record of another profile can't be saved in the current one.
     *
     * @return void
     */
    public function testRefusesToSaveARecordOfAnotherProfile(): void
    {
        $first = $this->createProfile();
        $second = $this->createProfile($first->organization);

        $this->expectException(LogicException::class);

        $this->inProfile($first, fn () => Account::factory()->create(['profile_id' => $second->id]));
    }

    /**
     * Test that a new record gets the current profile.
     *
     * @return void
     */
    public function testGivesANewRecordTheCurrentProfile(): void
    {
        $profile = $this->createProfile();

        $account = $this->inProfile($profile, fn () => Account::query()->create(['name' => 'Wise USD', 'kind' => 'platform', 'currency' => 'USD']));

        $this->assertSame($profile->id, $account->profile_id);
    }
}
