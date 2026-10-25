<?php

namespace Tests\Feature\Policies;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Client;
use PryTax\Models\EsvObligation;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxPayment;
use PryTax\Models\TaxPeriod;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class RolesTest.
 *
 * The roles of config/api-core.php (design.md §0): owners do everything, including profiles; accountants do every
 * financial operation and configure profiles, but don't change their identity or create them; viewers only read.
 * InvoicePolicyTest covers the invoices' statuses.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Policies
 */
class RolesTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * The abilities of each role on each model, with whether they are allowed.
     *
     * @return array<string, array{0: string, 1: class-string, 2: string, 3: bool}>
     */
    public static function abilities(): array
    {
        $finance = [Account::class, Client::class, Payment::class, Refund::class, AccountTransfer::class, TaxPayment::class];
        $cases = [];

        foreach ($finance as $model) {
            foreach (['create', 'update', 'delete'] as $ability) {
                $cases["accountant $ability " . class_basename($model)] = ['accountant', $model, $ability, true];
                $cases["viewer $ability " . class_basename($model)] = ['viewer', $model, $ability, false];
            }

            $cases['viewer view ' . class_basename($model)] = ['viewer', $model, 'view', true];
        }

        return [
            ...$cases,
            'accountant update TaxPeriod' => ['accountant', TaxPeriod::class, 'update', true],
            'viewer update TaxPeriod' => ['viewer', TaxPeriod::class, 'update', false],
            'accountant delete TaxPeriod' => ['accountant', TaxPeriod::class, 'delete', false],
            'accountant update EsvObligation' => ['accountant', EsvObligation::class, 'update', false],
            'viewer update EsvObligation' => ['viewer', EsvObligation::class, 'update', false],
            'accountant create ExchangeRate' => ['accountant', ExchangeRate::class, 'create', false],
            'owner create Profile' => ['owner', Profile::class, 'create', true],
            'owner update Profile' => ['owner', Profile::class, 'update', true],
            'accountant configure Profile' => ['accountant', Profile::class, 'configure', true],
            'accountant update Profile' => ['accountant', Profile::class, 'update', false],
            'accountant create Profile' => ['accountant', Profile::class, 'create', false],
            'accountant delete Profile' => ['accountant', Profile::class, 'delete', false],
            'viewer configure Profile' => ['viewer', Profile::class, 'configure', false],
        ];
    }

    /**
     * Test that the role has, or lacks, the ability on a record of the model.
     *
     * @param string $role
     * @param class-string $model
     * @param string $ability
     * @param bool $allowed
     *
     * @return void
     */
    #[DataProvider('abilities')]
    public function testGrantsEachRoleItsAbilities(string $role, string $model, string $ability, bool $allowed): void
    {
        $profile = $this->createProfile();
        $user = $this->userOf($profile->organization, $role);
        $record = $model === Profile::class
            ? $profile
            : $this->inProfile($profile, fn () => $model::factory()->create($model === ExchangeRate::class ? [] : ['profile_id' => $profile->id]));

        $this->assertSame($allowed, $user->can($ability, $ability === 'create' ? $model : $record));
    }
}
