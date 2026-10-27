<?php

namespace Tests\Feature\Backups;

use Core\Providers\MorphServiceProvider;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PryTax\Backups\PryTaxTableRegistry;
use PryTax\DataLifecycle\Registry\ColumnSnapshot;
use PryTax\DataLifecycle\Registry\MorphAliases;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Registry\TableSpec;
use PryTax\Models\Profile;
use PryTax\Providers\AppServiceProvider;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PryTaxTableRegistryTest.
 *
 * The registry of a ФОП's tables against the schema and the models (backups.md §4.2, §8): every table of profile
 * data is in it or excluded, the order is FK-safe, generated and encrypted columns are declared, every id inside a
 * value is rewritten, and every column is reviewed (the committed column snapshot).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class PryTaxTableRegistryTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * The registry.
     *
     * @var PryTaxTableRegistry
     */
    private PryTaxTableRegistry $registry;

    /**
     * The schema of the test database.
     *
     * @var SchemaInspector
     */
    private SchemaInspector $schema;

    /**
     * Set up the registry and the schema.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PryTaxTableRegistry;
        $this->schema = new SchemaInspector(DB::connection());
    }

    /**
     * Test that every table with `profile_id`, every child and every table with a morph pair is registered or
     * excluded with a reason.
     *
     * @return void
     */
    public function testCoversEveryTableOfProfileData(): void
    {
        $tables = $this->tables();
        $known = [...array_keys($tables), ...array_keys($this->registry->excluded())];

        foreach ($this->schema->tables() as $table) {
            if ($this->schema->hasColumn($table, 'profile_id') || $this->schema->morphPairs($table) !== []) {
                $this->assertContains($table, $known, "$table has profile data, but is neither registered nor excluded.");
            }
        }

        foreach ($tables as $name => $table) {
            $this->assertTrue($this->schema->hasTable($name), "$name is registered, but doesn't exist.");

            if ($table->parent() !== null) {
                $this->assertArrayHasKey($table->parent()['table'], $tables, "The parent of $name isn't registered.");
            }
        }

        $this->assertSame([], array_intersect(array_keys($tables), array_keys($this->registry->excluded())));
    }

    /**
     * Test that every table comes after the registered tables it references, unless the column is deferred; morph
     * tables after every possible owner; reference tables after the tables they are referenced through.
     *
     * @return void
     */
    public function testOrdersTheTablesByTheirForeignKeys(): void
    {
        $tables = $this->tables();
        $position = array_flip(array_keys($tables));
        $aliases = MorphAliases::byTable();

        foreach ($tables as $name => $table) {
            foreach ($this->schema->foreignKeys($name) as $column => $referenced) {
                if (!isset($tables[$referenced]) || $tables[$referenced]->isReference() || in_array($column, $table->deferredColumns(), true)) {
                    continue;
                }

                $this->assertLessThan($position[$name], $position[$referenced], "$name.$column references $referenced, which comes later.");
            }

            if ($table->isMorph()) {
                foreach ($tables as $owner => $spec) {
                    if ($spec->canOwnMorphs() && isset($aliases[$owner])) {
                        $this->assertLessThan($position[$name], $position[$owner], "$name comes before its possible owner $owner.");
                    }
                }
            }

            if ($table->isReference()) {
                foreach ([...$table->via(), ...array_map(fn ($via) => "{$via['table']}.", $table->viaMorphs())] as $via) {
                    $source = strtok($via, '.');

                    if ($source !== '*') {
                        $this->assertLessThan($position[$name], $position[$source], "$name comes before $source, which references it.");
                    }
                }
            }

            if (!$table->isReference()) {
                $this->assertTrue(
                    $table->isRoot() || $table->isMorph() || $table->strategy() === TableSpec::PROFILE_JSON || $position[$name] > $position['profiles'],
                    "$name comes before the profile."
                );
            }
        }

        foreach ($tables as $name => $table) {
            foreach ($table->deferredColumns() as $column) {
                $this->assertTrue($this->schema->columns($name)[$column]->nullable, "The deferred $name.$column isn't nullable.");
            }
        }
    }

    /**
     * Test that the generated columns of every registered table are the declared ones.
     *
     * @return void
     */
    public function testDeclaresEveryGeneratedColumn(): void
    {
        foreach ($this->tables() as $name => $table) {
            $generated = $this->schema->generatedColumns($name);
            $declared = $table->generatedColumns();
            sort($generated);
            sort($declared);

            $this->assertSame($generated, $declared, "The generated columns of $name differ from the declared ones.");
        }
    }

    /**
     * Test that every encrypted attribute of a registered table's model is decrypted, JSON ones as JSON, and nothing
     * else is.
     *
     * @return void
     */
    public function testDecryptsEveryEncryptedColumnOfTheModels(): void
    {
        $tables = $this->tables();
        $expected = [];

        foreach ([...MorphServiceProvider::getModelClasses(), ...AppServiceProvider::MODELS] as $class) {
            /** @var Model $model */
            $model = new $class;

            if (!isset($tables[$model->getTable()])) {
                continue;
            }

            foreach ($model->getCasts() as $attribute => $cast) {
                $json = match (true) {
                    $cast === 'encrypted' => false,
                    in_array($cast, ['encrypted:array', 'encrypted:collection', 'encrypted:object', AsEncryptedArrayObject::class, AsEncryptedCollection::class], true) => true,
                    default => null,
                };

                if ($json !== null) {
                    $expected[$model->getTable()][$attribute] = $json;
                }
            }
        }

        foreach ($tables as $name => $table) {
            $this->assertSame($expected[$name] ?? [], $table->decrypted(), "The decrypted columns of $name differ from the encrypted casts.");
        }

        $this->assertSame(['tax_id' => false], $expected['profiles']);
        $this->assertSame(['snapshot' => true], $expected['invoices']);
    }

    /**
     * Test that every id inside a value is rewritten on import: the `_id` / `_ids` keys of the profile's settings and
     * of an invoice's snapshot.
     *
     * @return void
     */
    public function testRewritesEveryIdInsideAValue(): void
    {
        Storage::fake('local');
        $tables = $this->tables();
        $settingKeys = [];

        foreach ($tables['settings']->rewrites() as $rewrite) {
            $settingKeys = [...$settingKeys, ...($rewrite->when['key'] ?? [])];
        }

        foreach (array_keys((new Profile)->settingDefinitions()) as $key) {
            if (preg_match('/_ids?$/', $key)) {
                $this->assertContains($key, $settingKeys, "The setting $key holds ids, but isn't rewritten.");
            }
        }

        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));
        $paths = array_map(fn ($rewrite) => $rewrite->json, array_filter($tables['invoices']->rewrites(), fn ($rewrite) => $rewrite->column === 'snapshot'));

        foreach ($this->keysOf($this->inProfile($profile, fn () => $invoice->fresh()->snapshot)) as $path) {
            // A tax id (РНОКПП, a client's tax number) is a number of the tax office, not an id of a row
            if (preg_match('/_ids?$/', $path) && !str_ends_with($path, '.tax_id')) {
                $this->assertContains($path, $paths, "The snapshot's $path holds an id, but isn't rewritten.");
            }
        }
    }

    /**
     * Test that the registry is the committed column snapshot's: a new, renamed or dropped column is reviewed (export
     * it, omit it or decrypt it), and the snapshot regenerated with `php artisan prytax:backup:snapshot`.
     *
     * @return void
     */
    public function testMatchesTheCommittedColumnSnapshot(): void
    {
        $path = config('data-lifecycle.snapshot_path');
        $this->assertFileExists($path, 'Run php artisan prytax:backup:snapshot.');

        $committed = json_decode((string) file_get_contents($path), true);
        $current = ColumnSnapshot::of($this->registry, $this->schema);
        $diff = ColumnSnapshot::diff($committed, $current);

        $this->assertSame(
            ['breaking' => [], 'additive' => []],
            $diff,
            'The registry differs from its committed snapshot. Review the columns, then run php artisan prytax:backup:snapshot'
            . ($diff['breaking'] !== [] ? ', after bumping PryTaxTableRegistry::FORMAT_VERSION (breaking changes).' : '.')
        );
    }

    /**
     * Test that a breaking change of the registry is told apart from an added column.
     *
     * @return void
     */
    public function testTellsABreakingChangeFromAnAddedColumn(): void
    {
        $snapshot = ColumnSnapshot::of($this->registry, $this->schema);

        $added = $snapshot;
        $added['tables']['clients']['columns']['nickname'] = 'export';
        $this->assertSame(['breaking' => [], 'additive' => ['clients.nickname: added (export)']], ColumnSnapshot::diff($snapshot, $added));

        $changed = $snapshot;
        $changed['tables']['clients']['columns']['email'] = 'omit';
        unset($changed['tables']['clients']['columns']['notes']);
        $this->assertSame(['clients.email: export → omit', 'clients.notes: removed'], ColumnSnapshot::diff($snapshot, $changed)['breaking']);
    }

    /**
     * Test that api-core's table-wide pruning of the audit log stays off: PryTax's retention decides (backups.md §4.2).
     *
     * @return void
     */
    public function testLeavesTheAuditLogToPryTaxsRetention(): void
    {
        $this->assertNull(config('api-core.audit.retention_days'));
    }

    /**
     * Get the registered tables by name.
     *
     * @return array<string, TableSpec>
     */
    private function tables(): array
    {
        $tables = [];

        foreach ($this->registry->tables() as $table) {
            $this->assertArrayNotHasKey($table->table(), $tables, "{$table->table()} is registered twice.");
            $tables[$table->table()] = $table;
        }

        return $tables;
    }

    /**
     * Get the JSON paths of every key of a value, such as `$.profile.signature_file_id`.
     *
     * @param array $value
     * @param string $prefix
     *
     * @return array<int, string>
     */
    private function keysOf(array $value, string $prefix = '$'): array
    {
        $paths = [];

        foreach ($value as $key => $item) {
            $path = is_int($key) ? $prefix : "$prefix.$key";

            if (!is_int($key)) {
                $paths[] = $path;
            }

            if (is_array($item)) {
                $paths = [...$paths, ...$this->keysOf($item, $path)];
            }
        }

        return array_values(array_unique($paths));
    }
}
