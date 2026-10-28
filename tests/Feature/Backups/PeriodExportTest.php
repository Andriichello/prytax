<?php

namespace Tests\Feature\Backups;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\DataLifecycle\Backup\Internal\ArchiveImporter;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\TransferAllocation;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;

/**
 * Class PeriodExportTest.
 *
 * An export of a ФОП's period (backups.md §2.7): the period's receipts, refunds, transfers, tax periods and ЄСВ
 * months, with the invoices of its receipts and the receipts of its transfers' allocations from outside the period
 * (marked `in_scope = 0`), the rest of the records whole; not restorable, kept a day, downloaded through a logged link.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class PeriodExportTest extends TestCase
{
    use ActsInProfiles;
    use ReadsArchives;
    use RefreshDatabase;

    /**
     * Test that a quarter's export holds what the quarter needs to read on its own, and isn't restorable.
     *
     * @return void
     */
    public function testExportsTheClosureOfTheQuarter(): void
    {
        Storage::fake('local');
        Storage::fake('backups');
        $this->cacheRates(['USD' => ['2026-10-05' => '41.4500']]);
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $paid = $this->createFrozenInvoice($profile, $account);
        $receipt = $this->recordReceipt($profile, $paid, $account);
        $lonely = $this->createFrozenInvoice($profile, $account, ['period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
        [$transfer, $earlier] = $this->inProfile($profile, function () use ($profile) {
            $earlier = Payment::factory()->create(['profile_id' => $profile->id, 'received_at' => '2026-09-15']);
            $transfer = AccountTransfer::factory()->create(['profile_id' => $profile->id, 'received_at' => '2026-10-10']);
            TransferAllocation::factory()->create(['transfer_id' => $transfer->id, 'payment_id' => $earlier->id, 'amount' => '100.00']);

            return [$transfer, $earlier];
        });
        $this->actAs($profile, 'accountant');

        $id = $this->postJson($this->profilePath($profile, 'exports'), ['from' => '2026-10-01', 'to' => '2026-12-31'])
            ->assertStatus(202)
            ->json('data.id');
        $response = $this->getJson($this->profilePath($profile, "exports/$id"))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.scope.from', '2026-10-01');

        $archive = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($archive, $this->get($response->json('data.url'))->assertOk()->streamedContent());

        $scope = fn (string $path) => collect($this->archiveRows($archive, $path))->pluck('in_scope', 'id')->all();
        $this->assertEquals([$earlier->id => '0', $receipt->id => '1'], $scope('data/payments.csv'));
        $this->assertArrayHasKey($paid->id, $scope('data/invoices.csv'));
        $this->assertSame('0', $scope('data/invoices.csv')[$paid->id]);
        $this->assertArrayNotHasKey($lonely->id, $scope('data/invoices.csv'));
        $this->assertSame([(string) $transfer->id], array_column($this->archiveRows($archive, 'data/account_transfers.csv'), 'id'));
        $this->assertSame(['4'], array_values(array_unique(array_column($this->archiveRows($archive, 'data/tax_periods.csv'), 'quarter'))));
        $this->assertSame(['10', '11', '12'], array_column($this->archiveRows($archive, 'data/esv_obligations.csv'), 'month'));
        $this->assertSame([(string) $paid->id], array_values(array_unique(array_column(array_filter(
            $this->archiveRows($archive, 'data/core/files.csv'),
            fn (array $row) => $row['fileable_type'] === 'models.invoices'
        ), 'fileable_id'))));
        // Records without a period are whole
        $this->assertCount(DB::table('accounts')->where('profile_id', $profile->id)->count(), $this->archiveRows($archive, 'data/accounts.csv'));

        $manifest = json_decode((string) $this->archiveFile($archive, 'manifest.json'), true);
        $this->assertFalse($manifest['restorable']);
        $this->assertSame(['type' => 'period', 'from' => '2026-10-01', 'to' => '2026-12-31'], $manifest['scope']);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The archive is an export of a period, which can\'t be restored.');

        try {
            app(ArchiveImporter::class)->import($archive, Organization::factory()->create()->id);
        } finally {
            unlink($archive);
        }
    }

    /**
     * Test that viewers can't export, and that an export of another ФОП isn't found.
     *
     * @return void
     */
    public function testShutsOutViewersAndOtherProfiles(): void
    {
        Storage::fake('backups');
        $profile = $this->createProfile();
        $other = $this->createProfile($profile->organization);
        $this->actAs($profile);
        $id = $this->postJson($this->profilePath($other, 'exports'), ['from' => '2026-01-01', 'to' => '2026-03-31'])->assertStatus(202)->json('data.id');

        $this->getJson($this->profilePath($profile, "exports/$id"))->assertNotFound();
        $this->postJson($this->profilePath($profile, 'exports'), ['from' => '2026-03-31', 'to' => '2026-01-01'])->assertJsonValidationErrors('to');

        $this->actAs($profile, 'viewer');
        $this->postJson($this->profilePath($profile, 'exports'), ['from' => '2026-01-01', 'to' => '2026-03-31'])->assertForbidden();
    }
}
