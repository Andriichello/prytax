<?php

namespace PryTax\DataLifecycle\Backup\Http\Requests;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StartArchiveUploadRequest.
 *
 * Starts uploading an archive in pieces, to restore it (backups.md §5.1): its bytes, so one larger than the import
 * takes is refused before it's sent.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Requests
 */
class StartArchiveUploadRequest extends StoreRequest
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
            'size' => ['required', 'integer', 'min:1'],
        ];
    }
}
