<?php

namespace PryTax\Retention;

use Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;
use Spatie\Permission\PermissionRegistrar;

/**
 * Class UserRemovalHandler.
 *
 * What PryTax adds to api-core's removal of a user (backups.md §9.2, §9.5), which runs first: api-core soft-deletes
 * the user, its tokens stop working, and a permanent delete hands its linked accounts over. Then its credentials go
 * (tokens, sessions, password resets, a recovery code), its access (roles and permissions), and its name and e-mail
 * are anonymized, keeping the row, so `created_by_user_id` and the audit log's causers still resolve; the e-mail is
 * freed for a new account, and the password becomes an unusable random hash (`users.password` isn't nullable).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
class UserRemovalHandler implements RetentionFamilyHandler
{
    /**
     * Name of a removed user.
     *
     * @var string
     */
    public const string ANONYMOUS_NAME = 'Видалений користувач';

    /**
     * Get the families, which the handler does.
     *
     * @return array<int, string>
     */
    public function families(): array
    {
        return ['credentials', 'access', 'user_pii'];
    }

    /**
     * Do the family for the removed user.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult
    {
        $userId = (int) $job->run->subject_id;

        return match ($job->family) {
            'credentials' => $this->credentials($userId),
            'access' => $this->access($userId),
            'user_pii' => $this->anonymize($userId),
        };
    }

    /**
     * Delete the user's tokens, sessions, password resets and recovery code.
     *
     * @param int $userId
     *
     * @return RetentionResult
     */
    public function credentials(int $userId): RetentionResult
    {
        $alias = (new User)->getMorphClass();
        $email = DB::table('users')->where('id', $userId)->value('email');

        $deleted = DB::table('personal_access_tokens')->where('tokenable_type', $alias)->where('tokenable_id', $userId)->delete()
            + DB::table('personal_access_tokens')->where('impersonator_id', $userId)->delete()
            + DB::table('sessions')->where('user_id', $userId)->delete()
            + DB::table('recovery_codes')->where('user_id', $userId)->delete()
            + ($email === null ? 0 : DB::table('password_reset_tokens')->where('email', $email)->delete());

        return new RetentionResult(processed: $deleted, deleted: $deleted);
    }

    /**
     * Delete the user's roles and permissions.
     *
     * @param int $userId
     *
     * @return RetentionResult
     */
    public function access(int $userId): RetentionResult
    {
        $alias = (new User)->getMorphClass();

        $deleted = DB::table('model_has_roles')->where('model_type', $alias)->where('model_id', $userId)->delete()
            + DB::table('model_has_permissions')->where('model_type', $alias)->where('model_id', $userId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return new RetentionResult(processed: $deleted, deleted: $deleted);
    }

    /**
     * Anonymize the deleted user.
     *
     * @param int $userId
     *
     * @return RetentionResult
     * @throws LogicException When the user isn't deleted: api-core's delete runs first, never this instead.
     */
    public function anonymize(int $userId): RetentionResult
    {
        $user = DB::table('users')->where('id', $userId)->first(['id', 'deleted_at']);

        if ($user !== null && $user->deleted_at === null) {
            throw new LogicException("The user $userId isn't deleted; it is never anonymized.");
        }

        $anonymized = DB::table('users')->where('id', $userId)->whereNotNull('deleted_at')->update([
            'name' => self::ANONYMOUS_NAME,
            'email' => "deleted+$userId@invalid",
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
        ]);

        return new RetentionResult(processed: $anonymized, anonymized: $anonymized);
    }
}
