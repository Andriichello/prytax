<?php

namespace PryTax\DataLifecycle\Backup\Http\Requests;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\DataLifecycle\Backup\Internal\ArchiveUploads;

/**
 * Class ImportBackupRequest.
 *
 * Restores a profile from its backup archive into the organization as a new profile (backups.md §5.1, in the app):
 * the archive (`.zip`, as downloaded), or the id of its upload in pieces with the file's name, its password, when it
 * was downloaded with one, and `dry_run`, which imports, verifies and rolls back, to tell what the archive holds
 * first.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Requests
 */
class ImportBackupRequest extends StoreRequest
{
    /**
     * The ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'import';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archive' => ['required_without:upload', 'file', 'extensions:zip', 'max:' . intdiv(ArchiveUploads::MAX_BYTES, 1024)],
            'upload' => ['required_without:archive', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:128'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
