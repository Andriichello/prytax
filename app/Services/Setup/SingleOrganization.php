<?php

namespace PryTax\Services\Setup;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PryTax\Exceptions\ArchiveRefusedException;
use PryTax\Models\RecoveryCode;

/**
 * Class SingleOrganization.
 *
 * One person's installation (`prytax.single_organization`, builds.md §1.4): the desktop app, a web app on this
 * computer, or a server kept for one person. It has one organization, registered with a password alone: its owner's
 * e-mail (`prytax+k3x9qa@prytax.invalid`, a domain, which never delivers) and its name (`prytax+fud-tor-wik`) are made
 * up. While it has one user, the password alone signs in; a forgotten one is set again with the recovery code, which
 * the recovery kit keeps, or with the installation's key, as nothing else could prove it's theirs.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Setup
 */
class SingleOrganization
{
    /**
     * The domain of the made-up e-mails, reserved by the standards, so nothing is ever delivered.
     *
     * @var string
     */
    public const string DOMAIN = 'prytax.invalid';

    /**
     * SingleOrganization constructor.
     *
     * @param OrganizationRegistrar $registrar
     */
    public function __construct(protected OrganizationRegistrar $registrar) {}

    /**
     * Determine whether the installation is one person's.
     *
     * @return bool
     */
    public function isOn(): bool
    {
        return (bool) config('prytax.single_organization');
    }

    /**
     * Determine whether an organization may be registered here: registration is on, and one person's installation
     * has none yet.
     *
     * @return bool
     */
    public function allowsRegistration(): bool
    {
        return (bool) config('prytax.registration') && (!$this->isOn() || !Organization::query()->withTrashed()->exists());
    }

    /**
     * Get the account, which the password alone signs into: the only user of the only organization, or null, when
     * the installation isn't one person's, or has more (a restored one may).
     *
     * @return User|null
     */
    public function account(): ?User
    {
        if (!$this->isOn() || Organization::query()->count() !== 1) {
            return null;
        }

        $users = User::query()->withoutGlobalScopes()->whereNull('deleted_at')->limit(2)->get();

        return $users->count() === 1 ? $users->first() : null;
    }

    /**
     * Register the organization and its owner with a password alone, with the ФОП of backup archives, if any, and
     * give the owner a recovery code.
     *
     * @param string $password
     * @param string $locale
     * @param list<array{path: string, password?: string|null, name?: string}> $archives
     *
     * @return User
     * @throws ValidationException When an organization exists already.
     * @throws ArchiveRefusedException
     */
    public function register(string $password, string $locale = 'uk', array $archives = []): User
    {
        return DB::transaction(function () use ($password, $locale, $archives) {
            if (Organization::query()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['password' => [__('PryTax here has its organization already: sign in.')]]);
            }

            $user = $this->registrar->register('prytax+' . $this->words(), 'PryTax', $this->email(), $password, $locale, $archives);
            $this->codeOf($user);

            return $user;
        });
    }

    /**
     * Get the user's recovery code, made when they have none.
     *
     * @param User $user
     *
     * @return RecoveryCode
     */
    public function codeOf(User $user): RecoveryCode
    {
        return RecoveryCode::query()->firstOrCreate(['user_id' => $user->id], ['code' => RecoveryCode::generate()]);
    }

    /**
     * Set a new password of the account with its recovery code or the installation's key, and get the account; its
     * other sessions' "remember me" ends with it.
     *
     * @param string $secret The recovery code (as typed, dashes and case aside) or the key (`base64:…`).
     * @param string $password
     *
     * @return User
     * @throws ValidationException When there's no such account, or the secret is neither.
     */
    public function resetPassword(string $secret, string $password): User
    {
        $user = $this->account();
        $code = $user === null ? null : RecoveryCode::query()->where('user_id', $user->id)->first();
        $typed = RecoveryCode::normalize($secret);
        $fits = ($code !== null && $typed !== '' && hash_equals(RecoveryCode::normalize($code->code), $typed))
            || hash_equals((string) config('app.key'), trim($secret));

        if ($user === null || !$fits) {
            throw ValidationException::withMessages(['secret' => [__('The recovery code or key isn\'t right.')]]);
        }

        $user->forceFill(['password' => $password, 'password_updated_at' => now(), 'remember_token' => Str::random(60)])->save();

        return $user;
    }

    /**
     * Make up an owner's e-mail, which no account has: `prytax+k3x9qa@prytax.invalid`.
     *
     * @return string
     */
    protected function email(): string
    {
        do {
            $email = 'prytax+' . $this->characters('abcdefghjkmnpqrstuvwxyz23456789', 6) . '@' . self::DOMAIN;
        } while (User::query()->withoutGlobalScopes()->where('email', $email)->exists());

        return $email;
    }

    /**
     * Make up three syllables of an organization's name: `fud-tor-wik`.
     *
     * @return string
     */
    protected function words(): string
    {
        return implode('-', array_map(
            fn () => $this->characters('bdfghjklmnprstvz', 1) . $this->characters('aeiou', 1) . $this->characters('bdfgklmnprstvz', 1),
            range(1, 3),
        ));
    }

    /**
     * Pick random characters of an alphabet.
     *
     * @param string $alphabet
     * @param int $length
     *
     * @return string
     */
    protected function characters(string $alphabet, int $length): string
    {
        $picked = '';

        for ($index = 0; $index < $length; $index++) {
            $picked .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $picked;
    }
}
