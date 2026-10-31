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
 * computer, or a server kept for one person. It has one organization, registered with a password alone: a made-up id
 * (`k3x-9qa-w7m`) is its name and its owner's e-mail (`k3x-9qa-w7m@prytax.invalid`, a domain, which never delivers).
 * While it has one user, the password alone signs in; a forgotten one is set again with the recovery code, which the
 * recovery kit keeps, or with the installation's key, as nothing else could prove it's theirs.
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
     * Determine whether an organization may be registered here: one person's installation registers its only one
     * while it has none yet, whether registration is on or not (its first visit chooses the password); any other
     * installation when registration is on.
     *
     * @return bool
     */
    public function allowsRegistration(): bool
    {
        if ($this->isOn()) {
            return !Organization::query()->withTrashed()->exists();
        }

        return (bool) config('prytax.registration');
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

            $id = $this->uniqueId();
            $user = $this->registrar->register($id, 'PryTax', $this->emailOf($id), $password, $locale, $archives);
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
     * Make up the installation's id, which no account's e-mail has: `k3x-9qa-w7m`, nine random characters in lower
     * case, as e-mails are kept in lower case and the organization's name must match.
     *
     * @return string
     */
    protected function uniqueId(): string
    {
        do {
            $id = implode('-', str_split(Str::lower(Str::random(9)), 3));
        } while (User::query()->withoutGlobalScopes()->where('email', $this->emailOf($id))->exists());

        return $id;
    }

    /**
     * Get the owner's e-mail of an id: `k3x-9qa-w7m@prytax.invalid`.
     *
     * @param string $id
     *
     * @return string
     */
    protected function emailOf(string $id): string
    {
        return $id . '@' . self::DOMAIN;
    }
}
