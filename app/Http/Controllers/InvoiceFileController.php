<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\FileController;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Crud\StoreRequest;
use Core\Http\Requests\Files\UpdateFileRequest;
use Core\Http\Responses\ApiResponse;
use Core\Models\File;
use Core\Repositories\FileRepository;
use Illuminate\Support\Facades\DB;
use PryTax\Enums\InvoiceStatus;
use PryTax\Http\Requests\Invoices\StoreInvoiceFileRequest;
use PryTax\Models\Invoice;
use PryTax\Policies\InvoicePolicy;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class InvoiceFileController.
 *
 * Files of an invoice: `profiles/{profile}/invoices/{invoice}/files`. Uploads go to the `uploaded` collection and
 * are never overwritten; with `primary=1`, the upload becomes the invoice's primary document, which suppresses the
 * generated PDF, and an uploaded primary document it replaces is deleted. An upload, whose contents another invoice
 * of the profile has already, is reported as a duplicate (by its SHA-256), not refused. The primary document of a
 * frozen invoice can't be replaced or deleted; returning the invoice to its draft keeps an uploaded one.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class InvoiceFileController extends FileController
{
    /**
     * Class of the model, whose files the controller handles.
     *
     * @var string
     */
    protected string $fileable = Invoice::class;

    /**
     * Route parameter of the model.
     *
     * @var string
     */
    protected string $parameter = 'invoice';

    /**
     * Route parameters of the model's parents.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile'];

    /**
     * Operations, which the controller handles, mapped to the classes of their requests: an upload takes `primary`.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'download' => ShowRequest::class,
        'store' => StoreInvoiceFileRequest::class,
        'update' => UpdateFileRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * InvoiceFileController constructor.
     *
     * @param InvoicePolicy $policy
     * @param FileRepository $repository
     */
    public function __construct(InvoicePolicy $policy, FileRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Upload a file of the invoice, making it primary, when asked of a draft, in place of the PDF uploaded before, and
     * reporting the invoices, which have the same file already. A frozen invoice takes further files, but keeps its
     * primary document.
     *
     * @param StoreRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStore(StoreRequest $request): ApiResponse
    {
        /** @var Invoice $invoice */
        $invoice = $this->fileableOf($request);

        if ($request->boolean('primary') && $invoice->status !== InvoiceStatus::Draft) {
            throw new ConflictHttpException(__('The primary document of a frozen invoice can\'t change.'));
        }

        $file = DB::transaction(function () use ($request, $invoice) {
            $replaced = $request->boolean('primary') ? $invoice->primaryFile : null;
            $file = $invoice->attachFile($request->file('file'), $request->validated('name'), Invoice::UPLOADED_COLLECTION);

            if ($request->boolean('primary')) {
                $invoice->forceFill(['primary_file_id' => $file->getKey()])->save();

                if ($replaced?->collection === Invoice::UPLOADED_COLLECTION) {
                    $replaced->delete();
                }
            }

            return $file;
        });

        $duplicates = File::query()
            ->where('fileable_type', $invoice->getMorphClass())
            ->where('sha256', $file->sha256)
            ->whereKeyNot($file->getKey())
            ->pluck('fileable_id')
            ->unique();

        $numbers = Invoice::query()->whereKey($duplicates)->get(['id', 'number'])
            ->map(fn (Invoice $duplicate) => ['invoice_id' => $duplicate->id, 'number' => $duplicate->number])
            ->values();

        return ApiResponse::created([
            'data' => [
                ...$this->asResource($file)->resolve($request),
                'is_primary' => $request->boolean('primary'),
                'duplicates' => $numbers->all(),
            ],
        ]);
    }

    /**
     * Delete a file of the invoice, unless it is the primary document of a frozen invoice.
     *
     * @param DestroyRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDestroy(DestroyRequest $request): ApiResponse
    {
        /** @var Invoice $invoice */
        $invoice = $this->fileableOf($request);

        if ($invoice->status !== InvoiceStatus::Draft && (int) $request->getId() === $invoice->primary_file_id) {
            throw new ConflictHttpException(__('The primary document of a frozen invoice can\'t be deleted.'));
        }

        return parent::handleDestroy($request);
    }
}
