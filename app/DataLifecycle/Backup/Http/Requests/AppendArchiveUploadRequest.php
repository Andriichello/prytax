<?php

namespace PryTax\DataLifecycle\Backup\Http\Requests;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\DataLifecycle\Backup\Internal\ArchiveUploads;

/**
 * Class AppendArchiveUploadRequest.
 *
 * Adds a piece to an archive's upload (backups.md §5.1): the piece, and the offset it starts at, which must be where
 * the upload ends.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Requests
 */
class AppendArchiveUploadRequest extends StoreRequest
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
            'piece' => ['required', 'file', 'max:' . intdiv(ArchiveUploads::MAX_PIECE, 1024)],
            'offset' => ['required', 'integer', 'min:0'],
        ];
    }
}
