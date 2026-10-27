<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\DataLifecycle\Registry\MorphAliases;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Registry\TableSpec;

/**
 * Class Selection.
 *
 * The rows of one tenant in every table of the registry, as query builder queries (never Eloquent, so no global
 * scope or context takes part, backups.md §4.4). Each strategy's predicate reuses the selections of the tables it
 * depends on as subqueries, so nothing is held in memory and every query sees the same snapshot. A purge deletes
 * through the same predicates (§9.5).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class Selection
{
    /**
     * Tables of the registry by name.
     *
     * @var array<string, TableSpec>
     */
    private array $tables = [];

    /**
     * Morph aliases of tables.
     *
     * @var array<string, string>
     */
    private array $aliases;

    /**
     * Selection constructor.
     *
     * @param ConnectionInterface $db
     * @param TableRegistry $registry
     * @param TenantRoot $root
     * @param SchemaInspector $schema
     * @param int $tenantId
     * @param RunSpec $spec
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        TableRegistry $registry,
        private readonly TenantRoot $root,
        private readonly SchemaInspector $schema,
        private readonly int $tenantId,
        private readonly RunSpec $spec,
    ) {
        foreach ($registry->tables() as $table) {
            $this->tables[$table->table()] = $table;
        }

        $this->aliases = MorphAliases::byTable();
    }

    /**
     * Get the query of the table's rows of the tenant.
     *
     * @param TableSpec $table
     *
     * @return Builder
     */
    public function query(TableSpec $table): Builder
    {
        $query = $this->db->table($table->table());

        if (!$table->enabledFor($this->spec)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->spec->isPeriod() && $table->periodFilter() !== null) {
            $query->where(function (Builder $query) use ($table) {
                $this->inPeriod($query, $table);

                foreach ($table->periodVia() as $via) {
                    [$source, $column] = explode('.', $via, 2);
                    $query->orWhereIn('id', $this->query($this->spec($source))->whereNotNull($column)->select($column));
                }
            });
        }

        return match ($table->strategy()) {
            TableSpec::ROOT => $query->where('id', $this->tenantId),
            TableSpec::PROFILE => $query->where($this->root->foreignKey(), $this->tenantId),
            TableSpec::CHILD => $query->whereIn($table->parent()['column'], $this->ids($this->spec($table->parent()['table']))),
            TableSpec::MORPH => $this->constrainMorph($query, $table),
            TableSpec::PROFILE_JSON => $query->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $query->getGrammar()->wrap($table->json()['column']) . ', ?)) AS UNSIGNED) = ?',
                [$table->json()['path'], $this->tenantId]
            ),
            TableSpec::REFERENCE => $this->constrainReference($query, $table),
        };
    }

    /**
     * Get the query of the ids of the table's rows of the tenant.
     *
     * @param TableSpec $table
     *
     * @return Builder
     */
    public function ids(TableSpec $table): Builder
    {
        return $this->query($table)->select('id');
    }

    /**
     * Get the tables of the registry, which may own the rows of morph tables: the root, profile and child ones, whose
     * model has a morph alias, with the alias.
     *
     * @return array<string, string> Table → alias.
     */
    public function morphOwners(): array
    {
        $owners = [];

        foreach ($this->tables as $name => $table) {
            if ($table->canOwnMorphs() && isset($this->aliases[$name])) {
                $owners[$name] = $this->aliases[$name];
            }
        }

        return $owners;
    }

    /**
     * Get the table of the registry, or fail.
     *
     * @param string $name
     *
     * @return TableSpec
     */
    public function spec(string $name): TableSpec
    {
        return $this->tables[$name] ?? throw new LogicException("The table $name isn't in the registry.");
    }

    /**
     * Limit the query to the rows of the run's period: a date in it, or a quarter or month, which overlaps it.
     *
     * @param Builder $query
     * @param TableSpec $table
     *
     * @return void
     */
    private function inPeriod(Builder $query, TableSpec $table): void
    {
        $filter = $table->periodFilter();
        [$from, $to] = [$this->spec->from->toDateString(), $this->spec->to->toDateString()];

        if ($filter['type'] === 'date') {
            $query->whereBetween($filter['column'], [$from, $to]);

            return;
        }

        $grammar = $query->getGrammar();
        [$year, $part] = [$grammar->wrap($filter['year']), $grammar->wrap($filter['part'])];
        $unit = $filter['type'] === 'quarter' ? 'QUARTER' : 'MONTH';
        $start = "MAKEDATE($year, 1) + INTERVAL ($part - 1) $unit";

        $query->whereRaw("$start <= ? AND $start + INTERVAL 1 $unit > ?", [$to, $from]);
    }

    /**
     * Determine if a row of the table is of the run's period itself, rather than taken with it.
     *
     * @param TableSpec $table
     * @param object $row
     *
     * @return bool
     */
    public function isInPeriod(TableSpec $table, object $row): bool
    {
        $filter = $table->periodFilter();

        if (!$this->spec->isPeriod() || $filter === null || $filter['type'] !== 'date') {
            return true;
        }

        $date = substr((string) $row->{$filter['column']}, 0, 10);

        return $date >= $this->spec->from->toDateString() && $date <= $this->spec->to->toDateString();
    }

    /**
     * Limit a morph table to the rows, whose pair names an exported row of an owner.
     *
     * @param Builder $query
     * @param TableSpec $table
     *
     * @return Builder
     */
    private function constrainMorph(Builder $query, TableSpec $table): Builder
    {
        $owners = $this->morphOwners();

        if ($owners === []) {
            return $query->whereRaw('1 = 0');
        }

        $morph = $table->morphName();

        return $query->where(function (Builder $query) use ($owners, $morph) {
            foreach ($owners as $owner => $alias) {
                $query->orWhere(fn (Builder $branch) => $branch
                    ->where("{$morph}_type", $alias)
                    ->whereIn("{$morph}_id", $this->ids($this->tables[$owner])));
            }
        });
    }

    /**
     * Limit a reference table to the rows, which exported rows reference.
     *
     * @param Builder $query
     * @param TableSpec $table
     *
     * @return Builder
     */
    private function constrainReference(Builder $query, TableSpec $table): Builder
    {
        $sources = [];

        foreach ($table->via() as $via) {
            [$name, $column] = explode('.', $via, 2);

            foreach ($name === '*' ? array_keys($this->tables) : [$name] as $source) {
                $spec = $this->tables[$source] ?? null;

                if ($spec && !$spec->isReference() && $spec->enabledFor($this->spec) && $this->schema->hasColumn($source, $column)) {
                    $sources[] = $this->query($spec)->whereNotNull($column)->select($column);
                }
            }
        }

        foreach ($table->viaMorphs() as $via) {
            $spec = $this->tables[$via['table']] ?? null;

            if ($spec && $spec->enabledFor($this->spec)) {
                $sources[] = $this->query($spec)->where("{$via['morph']}_type", $via['alias'])->select("{$via['morph']}_id");
            }
        }

        if ($sources === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($sources) {
            foreach ($sources as $source) {
                $query->orWhereIn('id', $source);
            }
        });
    }
}
