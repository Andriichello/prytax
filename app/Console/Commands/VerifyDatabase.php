<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\Enums\TaxType;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\TaxReconciler;
use Throwable;

/**
 * Class VerifyDatabase.
 *
 * The check of a restored database in the quarterly drill (backups.md §1.1): the rows of every table, no row whose
 * foreign key points at nothing (a dump restored without foreign key checks), encrypted columns readable with the app's
 * keys (D10: a copy is useless without them), and the tax balances of a sample of ФОП computed. It prints how long it
 * took, and fails on any problem.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:verify-db {--profiles=5 : How many ФОП to compute the tax balances of}')]
#[Description('Verify a restored database: row counts, orphaned foreign keys, encryption keys, tax balances')]
class VerifyDatabase extends Command
{
    /**
     * Execute the console command.
     *
     * @param TaxReconciler $reconciler
     *
     * @return int
     */
    public function handle(TaxReconciler $reconciler): int
    {
        $started = microtime(true);
        $schema = new SchemaInspector(DB::connection());
        $problems = [];

        $this->table(['Table', 'Rows'], array_map(fn (string $table) => [$table, DB::table($table)->count()], $schema->tables()));

        foreach ($schema->tables() as $table) {
            foreach ($schema->foreignKeys($table) as $column => $referenced) {
                $orphans = DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotExists(fn ($query) => $query->from($referenced)->whereColumn("$referenced.id", "$table.$column"))
                    ->count();

                if ($orphans > 0) {
                    $problems[] = "$table.$column: $orphans rows reference no $referenced row";
                }
            }
        }

        $profiles = Profile::query()->withoutGlobalScopes()->inRandomOrder()->limit((int) $this->option('profiles'))->get();

        foreach ($profiles as $profile) {
            try {
                Crypt::decryptString((string) $profile->getRawOriginal('tax_id'));

                $balances = CurrentProfile::run($profile, fn () => array_map(
                    fn (TaxType $type) => $type->value . ' ' . $reconciler->forYear($profile, $type, now()->year)['balance'],
                    TaxType::cases()
                ));

                $this->line("Profile $profile->id: " . implode(', ', $balances));
            } catch (DecryptException) {
                $problems[] = "Profile $profile->id: its tax id can't be decrypted with the app's keys (APP_KEY, APP_PREVIOUS_KEYS)";
            } catch (Throwable $exception) {
                $problems[] = "Profile $profile->id: {$exception->getMessage()}";
            }
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        $this->info(sprintf('Verified in %.1f s.', microtime(true) - $started));

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
