<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\FileController;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Files\StoreFileRequest;
use Core\Repositories\FileRepository;
use PryTax\Models\Profile;
use PryTax\Policies\ProfilePolicy;

/**
 * Class ProfileSignatureController.
 *
 * The signature image of a profile's PDFs: `profiles/{profile}/signatures`, the latest of which new invoices embed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ProfileSignatureController extends FileController
{
    /**
     * Class of the model, whose files the controller handles.
     *
     * @var string
     */
    protected string $fileable = Profile::class;

    /**
     * Route parameter of the model.
     *
     * @var string
     */
    protected string $parameter = 'profile';

    /**
     * Collection of the files.
     *
     * @var string|null
     */
    protected ?string $collection = Profile::SIGNATURE_COLLECTION;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'download' => ShowRequest::class,
        'store' => StoreFileRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * ProfileSignatureController constructor.
     *
     * @param ProfilePolicy $policy
     * @param FileRepository $repository
     */
    public function __construct(ProfilePolicy $policy, FileRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Get the abilities on the profile, which the abilities of the requests need: reading needs `view`, changing
     * `configure`, which accountants have without the `update` of the profile's identity.
     *
     * @return array<string, string>
     */
    protected function parentAbilities(): array
    {
        return [
            'viewAny' => 'view',
            'view' => 'view',
            'create' => 'configure',
            'update' => 'configure',
            'delete' => 'configure',
        ];
    }
}
