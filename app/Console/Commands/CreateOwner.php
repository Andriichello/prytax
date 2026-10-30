<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PryTax\Exceptions\InstallationAlreadySetUpException;
use PryTax\Services\Setup\InstallationSetup;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Class CreateOwner.
 *
 * Creates the organization and the owner of a fresh installation, such as a new Docker deployment, which has no
 * sign-up (builds.md §1.3). The details come from the options or prompts; the password is always
 * prompted, so it stays out of the shell's history and the process list.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:create-owner
    {--organization= : The name of the organization}
    {--name= : The owner\'s name}
    {--email= : The owner\'s e-mail, which they sign in with}
    {--locale=uk : The owner\'s language, uk or en}')]
#[Description('Create the organization and the owner of a fresh installation')]
class CreateOwner extends Command
{
    /**
     * Execute the console command.
     *
     * @param InstallationSetup $setup
     *
     * @return int
     */
    public function handle(InstallationSetup $setup): int
    {
        if (!$setup->isNeeded()) {
            $this->error('The installation already has users; invite more from the app.');

            return self::FAILURE;
        }

        $details = [
            'organization' => $this->option('organization') ?? text('Organization', required: true),
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
            $user = $setup->createOwner($details['organization'], $details['name'], $details['email'], $details['password'], $details['locale']);
        } catch (InstallationAlreadySetUpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("The owner {$user->email} can sign in now.");

        return self::SUCCESS;
    }
}
