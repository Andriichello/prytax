<?php

namespace Tests\Feature\Policies;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Enums\InvoiceStatus;
use PryTax\Models\Invoice;
use PryTax\Policies\InvoicePolicy;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoicePolicyTest.
 *
 * Which status allows which operation on an invoice (design.md §4), and that viewers change nothing.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Policies
 */
class InvoicePolicyTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Each ability with the statuses, which allow it.
     *
     * @return array<string, array{0: string, 1: array<int, InvoiceStatus>}>
     */
    public static function abilities(): array
    {
        return [
            'delete' => ['delete', [InvoiceStatus::Draft]],
            'freeze' => ['freeze', [InvoiceStatus::Draft]],
            'unfreeze' => ['unfreeze', [InvoiceStatus::Ready]],
            'send' => ['send', [InvoiceStatus::Ready]],
            'pay' => ['pay', [InvoiceStatus::Ready, InvoiceStatus::Sent]],
            'cancel' => ['cancel', [InvoiceStatus::Ready, InvoiceStatus::Sent]],
        ];
    }

    /**
     * Test that an accountant may do each ability in its statuses only.
     *
     * @param string $ability
     * @param array<int, InvoiceStatus> $allowed
     *
     * @return void
     */
    #[DataProvider('abilities')]
    public function testAllowsEachAbilityInItsStatusesOnly(string $ability, array $allowed): void
    {
        $profile = $this->createProfile();
        $accountant = $this->userOf($profile->organization, 'accountant');
        $policy = app(InvoicePolicy::class);

        foreach (InvoiceStatus::cases() as $status) {
            $invoice = new Invoice(['status' => $status]);

            $this->assertSame(
                in_array($status, $allowed, true),
                $policy->before($accountant, $ability) ?? $policy->$ability($accountant, $invoice),
                "$ability of a {$status->value} invoice",
            );
        }
    }

    /**
     * Test that a viewer has no permission to create, change or freeze invoices, but may read them.
     *
     * @return void
     */
    public function testLetsViewersOnlyRead(): void
    {
        $profile = $this->createProfile();
        $viewer = $this->userOf($profile->organization, 'viewer');
        $policy = app(InvoicePolicy::class);

        foreach (['create', 'update', 'delete', 'freeze', 'pay'] as $ability) {
            $this->assertFalse($policy->before($viewer, $ability), $ability);
        }

        $this->assertNull($policy->before($viewer, 'viewAny'));
    }
}
