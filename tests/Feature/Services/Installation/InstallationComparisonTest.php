<?php

namespace Tests\Feature\Services\Installation;

use PryTax\Services\Installation\InstallationComparison;
use Tests\Concerns\MakesInstallationDatabases;
use Tests\TestCase;

/**
 * Class InstallationComparisonTest.
 *
 * Comparing a full backup with this installation (builds.md §4.11): encrypted values compared decrypted by each
 * side's key, a fast-forward when the backup only adds and changes later, a rewrite with what this computer would
 * lose, history left out of the verdict, the schemas' order, and the last change of the data.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Installation
 */
class InstallationComparisonTest extends TestCase
{
    use MakesInstallationDatabases;

    /**
     * Test that databases with the same data are identical, though each key encrypts and hashes the tax ID its way.
     *
     * @return void
     */
    public function testFindsDatabasesIdenticalThoughEachKeyEncryptsThemDifferently(): void
    {
        [$backupKey, $currentKey] = [$this->makeKey(), $this->makeKey()];
        $backup = $this->smallDatabase(['profiles' => [$this->profile($backupKey)]]);
        $current = $this->smallDatabase(['profiles' => [$this->profile($currentKey)]]);

        $comparison = $this->compare($backup, $backupKey, $current, $currentKey);

        $this->assertSame('identical', $comparison['verdict']);
        $this->assertSame([], $comparison['tables']);
    }

    /**
     * Test that a backup, which only adds rows and changes them later than here, moves this installation forward.
     *
     * @return void
     */
    public function testTellsAFastForwardWhenTheBackupOnlyAddsAndChangesLater(): void
    {
        $key = $this->makeKey();
        $backup = $this->smallDatabase(['invoices' => [
            ['id' => 1, 'number' => 'BL-1', 'status' => 'paid', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-20 10:00:00'],
            ['id' => 2, 'number' => 'BL-2', 'status' => 'draft', 'created_at' => '2026-10-21 10:00:00', 'updated_at' => '2026-10-21 10:00:00'],
        ]]);
        $current = $this->smallDatabase(['invoices' => [
            ['id' => 1, 'number' => 'BL-1', 'status' => 'sent', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-05 10:00:00'],
        ]]);

        $comparison = $this->compare($backup, $key, $current, $key);

        $this->assertSame(['fast_forward', 0], [$comparison['verdict'], $comparison['lost']]);
        $invoices = $comparison['tables'][0];
        $this->assertSame(['invoices', 1, 1, 0, 0], [$invoices['table'], $invoices['added'], $invoices['changed'], $invoices['removed'], $invoices['newer_here']]);
        $this->assertSame([['column' => 'status', 'backup' => 'paid', 'here' => 'sent'], ['column' => 'updated_at', 'backup' => '2026-10-20 10:00:00', 'here' => '2026-10-05 10:00:00']], $invoices['rows'][0]['columns']);
        $this->assertSame(['added', '2', 'BL-2'], [$invoices['rows'][1]['kind'], $invoices['rows'][1]['id'], $invoices['rows'][1]['label']]);
    }

    /**
     * Test that a backup, which lacks a row of this installation, or has an older version of one, rewrites it, and
     * that what would be lost is listed first.
     *
     * @return void
     */
    public function testTellsARewriteWithWhatThisComputerWouldLose(): void
    {
        $key = $this->makeKey();
        $backup = $this->smallDatabase(['invoices' => [
            ['id' => 1, 'number' => 'BL-1', 'status' => 'sent', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-05 10:00:00'],
            ['id' => 2, 'number' => 'BL-2', 'status' => 'draft', 'created_at' => '2026-10-02 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
        ]]);
        $current = $this->smallDatabase(['invoices' => [
            ['id' => 1, 'number' => 'BL-1', 'status' => 'paid', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-25 10:00:00'],
            ['id' => 2, 'number' => 'BL-2', 'status' => 'draft', 'created_at' => '2026-10-02 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
            ['id' => 3, 'number' => 'BL-3', 'status' => 'draft', 'created_at' => '2026-10-26 10:00:00', 'updated_at' => '2026-10-26 10:00:00'],
        ]]);

        $comparison = $this->compare($backup, $key, $current, $key);

        $this->assertSame(['rewrite', 2], [$comparison['verdict'], $comparison['lost']]);
        $invoices = $comparison['tables'][0];
        $this->assertSame([0, 1, 1, 1], [$invoices['added'], $invoices['changed'], $invoices['removed'], $invoices['newer_here']]);
        $this->assertSame([['changed', '1', true], ['removed', '3', true]], array_map(fn (array $row) => [$row['kind'], $row['id'], $row['newer_here']], $invoices['rows']));
    }

    /**
     * Test that the audit log, which differs as each computer is used, is shown but leaves the verdict alone.
     *
     * @return void
     */
    public function testLeavesHistoryOutOfTheVerdict(): void
    {
        $key = $this->makeKey();
        $backup = $this->smallDatabase([]);
        $current = $this->smallDatabase(['audit_logs' => [['id' => 1, 'event' => 'login', 'created_at' => '2026-10-30 09:00:00']]]);

        $comparison = $this->compare($backup, $key, $current, $key);

        $this->assertSame('identical', $comparison['verdict']);
        $this->assertSame([['audit_logs', false, 1]], array_map(fn (array $table) => [$table['table'], $table['counted'], $table['removed']], $comparison['tables']));
        $this->assertNull($comparison['current']['last_change_at']);
    }

    /**
     * Test that signing in here (a user's last sign-in, remember token and touched row) is no change of the data,
     * neither in the verdict nor in the last change.
     *
     * @return void
     */
    public function testTakesSigningInForNoChange(): void
    {
        $key = $this->makeKey();
        $user = ['id' => 1, 'name' => 'Олена', 'email' => 'olena@example.com', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'];
        $backup = $this->smallDatabase(['users' => [$user]]);
        $current = $this->smallDatabase(['users' => [['last_login_at' => '2026-10-20 09:00:00', 'remember_token' => 'abc', 'updated_at' => '2026-10-20 09:00:00'] + $user]]);

        $comparison = $this->compare($backup, $key, $current, $key);

        $this->assertSame('identical', $comparison['verdict']);
        $this->assertNull($comparison['current']['last_change_at']);
    }

    /**
     * Test that a backup's schema is older with fewer migrations, which a restore runs, and newer with some, which
     * this app doesn't know.
     *
     * @return void
     */
    public function testTellsWhetherTheBackupsSchemaIsOlderOrNewer(): void
    {
        $key = $this->makeKey();
        $current = $this->smallDatabase([], ['a', 'b']);

        $this->assertSame('older', $this->compare($this->smallDatabase([], ['a']), $key, $current, $key)['schema']);
        $this->assertSame('newer', $this->compare($this->smallDatabase([], ['a', 'b', 'c']), $key, $current, $key)['schema']);
        $this->assertSame('same', $this->compare($this->smallDatabase([], ['a', 'b']), $key, $current, $key)['schema']);
    }

    /**
     * Test that the last change of the data is the latest time a row of the data was made or changed.
     *
     * @return void
     */
    public function testTellsWhenTheDataChangedLast(): void
    {
        $file = $this->smallDatabase(['invoices' => [
            ['id' => 1, 'number' => 'BL-1', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-20 14:30:00'],
            ['id' => 2, 'number' => 'BL-2', 'created_at' => '2026-10-19 10:00:00', 'updated_at' => '2026-10-19 10:00:00'],
        ]]);

        $this->assertSame('2026-10-20T14:30:00+03:00', app(InstallationComparison::class)->lastChange(InstallationComparison::open($file)));
    }

    /**
     * Get a ФОП's row with its tax ID encrypted and hashed by the key.
     *
     * @param string $key
     *
     * @return array<string, mixed>
     */
    private function profile(string $key): array
    {
        return [
            'id' => 1,
            'organization_id' => 1,
            'full_name' => 'Коваленко Олена Петрівна',
            'tax_id' => $this->encryptWith($key, '3214704821'),
            'tax_id_hash' => hash_hmac('sha256', '3214704821', $key),
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
        ];
    }

    /**
     * Compare two small databases.
     *
     * @param string $backup
     * @param string $backupKey
     * @param string $current
     * @param string $currentKey
     *
     * @return array<string, mixed>
     */
    private function compare(string $backup, string $backupKey, string $current, string $currentKey): array
    {
        return app(InstallationComparison::class)->compare(
            InstallationComparison::open($backup),
            $backupKey,
            InstallationComparison::open($current),
            $currentKey,
        );
    }
}
