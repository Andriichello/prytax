<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PryTax\Backups\ProfileArchives;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\Exceptions\ArchiveRefusedException;
use PryTax\Exceptions\InstallationAlreadySetUpException;
use PryTax\Services\Setup\InstallationSetup;
use PryTax\Services\Setup\SingleOrganization;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Class CreateOwner.
 *
 * Creates the organization and the owner of a fresh installation, such as a new Docker deployment, which has no
 * sign-up (builds.md §1.3). The details come from the options or prompts; the password is always
 * prompted, so it stays out of the shell's history and the process list. With `--from`, the organization starts with
 * the ФОПs of backup archives (a ФОП's, or a backup's bundle of every ФОП), all of them or none: moving from the
 * desktop app to a server, say. An archive's password is prompted too. On one person's installation (builds.md §1.4)
 * only the password is asked for; the made-up e-mail and the recovery code are printed, to keep.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:create-owner
    {--organization= : The name of the organization}
    {--name= : The owner\'s name}
    {--email= : The owner\'s e-mail, which they sign in with}
    {--locale=uk : The owner\'s language, uk or en}
    {--from=* : A backup archive of ФОПs, which the organization starts with (repeat it for more)}')]
#[Description('Create the organization and the owner of a fresh installation')]
class CreateOwner extends Command
{
    /**
     * Execute the console command.
     *
     * @param InstallationSetup $setup
     * @param ProfileArchives $profiles
     * @param SingleOrganization $single
     *
     * @return int
     */
    public function handle(InstallationSetup $setup, ProfileArchives $profiles, SingleOrganization $single): int
    {
        if (!$setup->isNeeded()) {
            $this->error('The installation already has users; invite more from the app.');

            return self::FAILURE;
        }

        $archives = $this->archives($profiles);

        if ($archives === null) {
            return self::FAILURE;
        }

        if ($single->isOn()) {
            return $this->createSingleOwner($setup, $single, $archives);
        }

        $details = [
            'organization' => $this->option('organization') ?? text('Organization', default: $archives[0]['organization'] ?? '', required: true),
            'name' => $this->option('name') ?? text('Owner\'s name', required: true),
            'email' => $this->option('email') ?? text('Owner\'s e-mail', required: true),
            'locale' => $this->option('locale'),
            'password' => password('Password', required: true),
            'password_confirmation' => password('Repeat the password', required: true),
        ];

        $validator = Validator::make($details, [
            'organization' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['required', 'in:' . implode(',', config('api-core.locales'))],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = $setup->createOwner($details['organization'], $details['name'], $details['email'], $details['password'], $details['locale'], $archives);
        } catch (InstallationAlreadySetUpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ArchiveRefusedException $exception) {
            $this->error("{$archives[$exception->archive]['name']}: {$exception->getMessage()} Nothing was created.");

            return self::FAILURE;
        }

        $this->info("The owner {$user->email} can sign in now.");

        return self::SUCCESS;
    }

    /**
     * Create the owner of one person's installation with a password alone, and print what signs in and recovers.
     *
     * @param InstallationSetup $setup
     * @param SingleOrganization $single
     * @param list<array{path: string, password: string|null, name: string, organization: string|null}> $archives
     *
     * @return int
     */
    protected function createSingleOwner(InstallationSetup $setup, SingleOrganization $single, array $archives): int
    {
        $details = [
            'password' => password('Password', required: true),
            'password_confirmation' => password('Repeat the password', required: true),
        ];
        $validator = Validator::make($details, ['password' => ['required', 'string', 'confirmed', Password::defaults()]]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = $setup->createSingleOwner($details['password'], (string) $this->option('locale'), $archives);
        } catch (InstallationAlreadySetUpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ArchiveRefusedException $exception) {
            $this->error("{$archives[$exception->archive]['name']}: {$exception->getMessage()} Nothing was created.");

            return self::FAILURE;
        }

        $this->info('The owner can sign in now, with the password alone.');
        $this->line("E-mail (made up, it receives nothing): {$user->email}");
        $this->line("Recovery code, which sets a new password when it's forgotten: {$single->codeOf($user)->code}");
        $this->warn('Keep the recovery code away from this server: it is the only way back in without the password.');

        return self::SUCCESS;
    }

    /**
     * Get the archives of `--from`, each with its password, when it has one, after telling its ФОПs; null when one
     * can't be read.
     *
     * @param ProfileArchives $profiles
     *
     * @return list<array{path: string, password: string|null, name: string, organization: string|null}>|null
     */
    protected function archives(ProfileArchives $profiles): ?array
    {
        $archives = [];

        foreach ((array) $this->option('from') as $path) {
            $name = basename($path);

            if (!is_file($path)) {
                $this->error("$name: there's no such file.");

                return null;
            }

            try {
                [$password, $found] = $this->inspect($profiles, $path, $name);
            } catch (ImportException $exception) {
                $this->error("$name: {$exception->getMessage()}");

                return null;
            }

            foreach ($found as $profile) {
                $last4 = $profile['tax_id_last4'] === null ? '' : " (•••• {$profile['tax_id_last4']})";
                $this->line("$name: {$profile['full_name']}$last4, {$profile['rows']} rows, {$profile['documents']} documents");
            }

            $archives[] = ['path' => $path, 'password' => $password, 'name' => $name, 'organization' => $found[0]['organization']];
        }

        return $archives;
    }

    /**
     * Read the ФОПs of an archive, asking for its password, when it has one.
     *
     * @param ProfileArchives $profiles
     * @param string $path
     * @param string $name
     *
     * @return array{0: string|null, 1: list<array<string, mixed>>} The password, and the ФОПs.
     * @throws ImportException
     */
    protected function inspect(ProfileArchives $profiles, string $path, string $name): array
    {
        try {
            return [null, $profiles->inspect($path)];
        } catch (ImportException $exception) {
            if ($exception->getCode() !== ImportException::PASSWORD_REQUIRED) {
                throw $exception;
            }

            $password = password("The password of $name", required: true);

            return [$password, $profiles->inspect($path, $password)];
        }
    }
}
