<?php

namespace PryTax\Services\Installation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use Pdo\Sqlite;
use Throwable;

/**
 * Class InstallationComparison.
 *
 * What restoring a full backup would change here (builds.md §4.11): every table of both databases, row by row (by id,
 * or by the whole row without one), with encrypted values decrypted by each side's own key. A row only in the backup
 * comes with it; a row only here, or one changed here later than the backup's, is lost. Without such losses the
 * restore only moves this installation forward (a fast-forward), otherwise it rewrites it. History, derived rows,
 * caches and seeded rows are shown, but don't decide it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
class InstallationComparison
{
    /**
     * The rows listed of each table, those lost first.
     *
     * @var int
     */
    public const int SAMPLE = 100;

    /**
     * The characters shown of a value.
     *
     * @var int
     */
    public const int VALUE_LENGTH = 120;

    /**
     * The tables, which tell a database of PryTax.
     *
     * @var list<string>
     */
    protected const array REQUIRED = ['migrations', 'organizations', 'users', 'profiles'];

    /**
     * Open a SQLite database, read-only unless asked otherwise.
     *
     * @param string $path
     * @param bool $readOnly
     *
     * @return Sqlite
     */
    public static function open(string $path, bool $readOnly = true): Sqlite
    {
        return new Sqlite('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ...($readOnly ? [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY] : []),
        ]);
    }

    /**
     * Check that the database is PryTax's, and that the key opens its encrypted values.
     *
     * @param PDO $db
     * @param string $key
     *
     * @return void
     * @throws ValidationException
     */
    public function verify(PDO $db, string $key): void
    {
        if (array_diff(self::REQUIRED, $this->tables($db)) !== []) {
            throw ValidationException::withMessages(['archive' => [__('The file isn\'t a full backup of PryTax.')]]);
        }

        $encrypted = $db->query('SELECT tax_id FROM profiles WHERE tax_id IS NOT NULL LIMIT 1')->fetchColumn();

        try {
            if ($encrypted !== false) {
                $this->encrypter($key)->decryptString((string) $encrypted);
            }
        } catch (DecryptException) {
            throw ValidationException::withMessages(['archive' => [__('The backup\'s key doesn\'t open its database: the file is damaged.')]]);
        }
    }

    /**
     * Compare the backup's database with this installation's.
     *
     * @param PDO $backup
     * @param string $backupKey
     * @param PDO $current
     * @param string $currentKey
     *
     * @return array{verdict: string, lost: int, schema: string, backup: array<string, mixed>, current: array<string, mixed>, tables: list<array<string, mixed>>}
     */
    public function compare(PDO $backup, string $backupKey, PDO $current, string $currentKey): array
    {
        $encrypters = ['backup' => $this->encrypter($backupKey), 'current' => $this->encrypter($currentKey)];
        $tables = array_diff(array_unique([...$this->tables($backup), ...$this->tables($current)]), InstallationTables::IGNORED);
        sort($tables);
        $differences = [];

        foreach ($tables as $table) {
            $difference = $this->table($table, $backup, $current, $encrypters);

            if ($difference['added'] + $difference['changed'] + $difference['removed'] > 0) {
                $differences[] = $difference;
            }
        }

        $counted = array_filter($differences, fn (array $table) => $table['counted']);
        $lost = array_sum(array_map(fn (array $table) => $table['removed'] + $table['newer_here'], $counted));

        return [
            'verdict' => $counted === [] ? 'identical' : ($lost === 0 ? 'fast_forward' : 'rewrite'),
            'lost' => $lost,
            'schema' => $this->schemaOf($backup, $current),
            'backup' => $this->describe($backup),
            'current' => $this->describe($current),
            'tables' => $differences,
        ];
    }

    /**
     * Describe a database: when it changed last, its organizations and its schema.
     *
     * @param PDO $db
     *
     * @return array{last_change_at: string|null, organizations: list<array{id: int, name: string, profiles: int}>, migration: string|null}
     */
    public function describe(PDO $db): array
    {
        return [
            'last_change_at' => $this->lastChange($db),
            'organizations' => $this->organizations($db),
            'migration' => $db->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null,
        ];
    }

    /**
     * Get when the database's data changed last: the latest time a counted table's row was made or changed (but a
     * user's, which signing in touches).
     *
     * @param PDO $db
     *
     * @return string|null In ISO 8601.
     */
    public function lastChange(PDO $db): ?string
    {
        $latest = null;

        foreach (array_diff($this->tables($db), InstallationTables::IGNORED, InstallationTables::NOT_COUNTED, InstallationTables::UNDATED) as $table) {
            foreach (array_intersect(['updated_at', 'created_at'], $this->columns($db, $table)) as $column) {
                $time = $db->query(sprintf('SELECT MAX("%s") FROM "%s"', $column, $table))->fetchColumn();

                if (is_string($time) && ($latest === null || $time > $latest)) {
                    $latest = $time;
                }
            }
        }

        return $this->iso($latest);
    }

    /**
     * Get the organizations of the database, with their ФОП, neither deleted.
     *
     * @param PDO $db
     *
     * @return list<array{id: int, name: string, profiles: int}>
     */
    public function organizations(PDO $db): array
    {
        $rows = $db->query('SELECT o.id, o.name, (SELECT COUNT(*) FROM profiles p WHERE p.organization_id = o.id AND p.deleted_at IS NULL) AS profiles'
            . ' FROM organizations o WHERE o.deleted_at IS NULL ORDER BY o.id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'profiles' => (int) $row['profiles']], $rows);
    }

    /**
     * Get the tables of a database.
     *
     * @param PDO $db
     *
     * @return list<string>
     */
    public function tables(PDO $db): array
    {
        return $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get the columns of a table, or none of a table, which the database hasn't.
     *
     * @param PDO $db
     * @param string $table
     *
     * @return list<string>
     */
    public function columns(PDO $db, string $table): array
    {
        $statement = $db->prepare('SELECT name FROM pragma_table_info(?)');
        $statement->execute([$table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Compare a table of both databases.
     *
     * @param string $table
     * @param PDO $backup
     * @param PDO $current
     * @param array{backup: Encrypter, current: Encrypter} $encrypters
     *
     * @return array{table: string, counted: bool, added: int, changed: int, removed: int, newer_here: int, rows: list<array<string, mixed>>}
     */
    protected function table(string $table, PDO $backup, PDO $current, array $encrypters): array
    {
        $columns = ['backup' => $this->columns($backup, $table), 'current' => $this->columns($current, $table)];
        $shared = $columns['backup'] === [] || $columns['current'] === []
            ? [...$columns['backup'], ...$columns['current']]
            : array_values(array_intersect($columns['backup'], $columns['current']));
        // A column computed with the key differs by key alone; signing in writes others
        $compared = array_values(array_diff($shared, array_keys(InstallationTables::KEYED[$table] ?? []), InstallationTables::VOLATILE[$table] ?? []));
        $keyed = in_array('id', $compared, true);
        $time = in_array('updated_at', $compared, true) ? 'updated_at' : (in_array('created_at', $compared, true) ? 'created_at' : null);
        $label = current(array_intersect(InstallationTables::LABELS, $compared)) ?: null;
        $backupRows = $this->rows($backup, $table, $compared, $encrypters['backup'], $keyed);
        $currentRows = $this->rows($current, $table, $compared, $encrypters['current'], $keyed);
        [$rows, $counts] = [[], ['added' => 0, 'changed' => 0, 'removed' => 0, 'newer_here' => 0]];

        foreach ($currentRows as $key => $here) {
            $there = $backupRows[$key] ?? null;

            if ($there === null) {
                $counts['removed']++;
                $rows[] = $this->sample('removed', $here, null, $here, $keyed, $label, $time);

                continue;
            }

            $changed = array_values(array_filter($compared, fn (string $column) => $there[$column] !== $here[$column]));

            // A row only touched is the same row
            if (array_diff($changed, ['updated_at']) === []) {
                continue;
            }

            $newer = $time !== null && $here[$time] !== null && $there[$time] !== null && $here[$time] > $there[$time];
            $counts['changed']++;
            $counts['newer_here'] += $newer ? 1 : 0;
            $rows[] = [
                ...$this->sample('changed', $here, $there, $here, $keyed, $label, $time),
                'newer_here' => $newer,
                'columns' => array_map(fn (string $column) => [
                    'column' => $column,
                    'backup' => $this->shown($there[$column]),
                    'here' => $this->shown($here[$column]),
                ], $changed),
            ];
        }

        foreach (array_diff_key($backupRows, $currentRows) as $there) {
            $counts['added']++;
            $rows[] = $this->sample('added', $there, $there, null, $keyed, $label, $time);
        }

        // What would be lost first, then what changes, then what comes
        $order = fn (array $row) => match (true) {
            $row['kind'] === 'removed' || $row['newer_here'] => 0,
            $row['kind'] === 'changed' => 1,
            default => 2,
        };
        usort($rows, fn (array $a, array $b) => $order($a) <=> $order($b));

        return [
            'table' => $table,
            'counted' => !in_array($table, InstallationTables::NOT_COUNTED, true),
            ...$counts,
            'rows' => array_slice($rows, 0, self::SAMPLE),
        ];
    }

    /**
     * Get the rows of a table with the columns compared, their encrypted values decrypted, by id (or by the whole row).
     *
     * @param PDO $db
     * @param string $table
     * @param list<string> $columns
     * @param Encrypter $encrypter
     * @param bool $keyed
     *
     * @return array<string, array<string, string|null>>
     */
    protected function rows(PDO $db, string $table, array $columns, Encrypter $encrypter, bool $keyed): array
    {
        $present = $this->columns($db, $table);

        if ($present === []) {
            return [];
        }

        $encrypted = InstallationTables::ENCRYPTED[$table] ?? [];
        $rows = [];

        foreach ($db->query(sprintf('SELECT * FROM "%s"', $table))->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values = [];

            foreach ($columns as $column) {
                $value = array_key_exists($column, $row) && $row[$column] !== null ? (string) $row[$column] : null;
                $values[$column] = $value !== null && in_array($column, $encrypted, true) ? $this->decrypt($encrypter, $value) : $value;
            }

            $rows[$keyed ? (string) $values['id'] : hash('sha256', json_encode($values) ?: '')] = $values;
        }

        return $rows;
    }

    /**
     * Describe a row of the comparison.
     *
     * @param string $kind `added`, `changed` or `removed`.
     * @param array<string, string|null> $row The row to name.
     * @param array<string, string|null>|null $there The backup's version.
     * @param array<string, string|null>|null $here This installation's version.
     * @param bool $keyed
     * @param string|null $label
     * @param string|null $time
     *
     * @return array{kind: string, id: string|null, label: string|null, backup_at: string|null, here_at: string|null, newer_here: bool, columns: list<array<string, mixed>>}
     */
    protected function sample(string $kind, array $row, ?array $there, ?array $here, bool $keyed, ?string $label, ?string $time): array
    {
        $name = $label !== null ? $row[$label] : null;

        // A row without an id is named by its values
        if (!$keyed) {
            $name = implode(', ', array_map(fn (string $column, ?string $value) => "$column=$value", array_keys($row), $row));
        }

        return [
            'kind' => $kind,
            'id' => $keyed ? $row['id'] : null,
            'label' => $this->shown($name),
            'backup_at' => $time !== null && $there !== null ? $this->iso($there[$time]) : null,
            'here_at' => $time !== null && $here !== null ? $this->iso($here[$time]) : null,
            'newer_here' => $kind === 'removed',
            'columns' => [],
        ];
    }

    /**
     * Get which schema the backup has, as against this installation's: the same migrations, older (fewer, which a
     * restore runs) or newer (some, which this app doesn't know).
     *
     * @param PDO $backup
     * @param PDO $current
     *
     * @return string `same`, `older` or `newer`.
     */
    public function schemaOf(PDO $backup, PDO $current): string
    {
        $there = $backup->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $here = $current->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        return match (true) {
            array_diff($there, $here) !== [] => 'newer',
            array_diff($here, $there) !== [] => 'older',
            default => 'same',
        };
    }

    /**
     * Get an encrypter of a key (`base64:…`).
     *
     * @param string $key
     *
     * @return Encrypter
     */
    protected function encrypter(string $key): Encrypter
    {
        return new Encrypter(base64_decode(Str::after($key, 'base64:')), (string) config('app.cipher'));
    }

    /**
     * Decrypt a value, or tell that it can't be.
     *
     * @param Encrypter $encrypter
     * @param string $value
     *
     * @return string
     */
    protected function decrypt(Encrypter $encrypter, string $value): string
    {
        try {
            return $encrypter->decryptString($value);
        } catch (DecryptException) {
            return '(unreadable)';
        }
    }

    /**
     * Shorten a value to be shown.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    protected function shown(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, self::VALUE_LENGTH);
    }

    /**
     * Get a database's time (the app's time zone) in ISO 8601.
     *
     * @param string|null $time
     *
     * @return string|null
     */
    protected function iso(?string $time): ?string
    {
        try {
            return $time === null ? null : CarbonImmutable::parse($time, (string) config('app.timezone'))->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
