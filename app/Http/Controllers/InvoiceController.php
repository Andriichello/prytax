<?php

namespace PryTax\Http\Controllers;

use Carbon\CarbonImmutable;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Crud\UpdateRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use PryTax\Http\Requests\Invoices\CancelInvoiceRequest;
use PryTax\Http\Requests\Invoices\FreezeInvoiceRequest;
use PryTax\Http\Requests\Invoices\PayInvoiceRequest;
use PryTax\Http\Requests\Invoices\PreviewNumberRequest;
use PryTax\Http\Requests\Invoices\SendInvoiceRequest;
use PryTax\Http\Requests\Invoices\StoreInvoiceRequest;
use PryTax\Http\Requests\Invoices\UnfreezeInvoiceRequest;
use PryTax\Http\Requests\Invoices\UpdateInvoiceRequest;
use PryTax\Http\Resources\InvoiceCollection;
use PryTax\Http\Resources\InvoiceResource;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Policies\InvoicePolicy;
use PryTax\Repositories\InvoiceRepository;
use PryTax\Services\Invoices\InvoiceFreezer;
use PryTax\Services\Invoices\InvoiceLifecycle;
use PryTax\Services\Invoices\InvoiceNumberGenerator;
use PryTax\Services\Invoices\InvoicePdfRenderer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Class InvoiceController.
 *
 * Invoices of a profile: `profiles/{profile}/invoices`, a list filtered by `period`, `issued_at`, `client_id`,
 * `currency` and `status` and paged for infinite scroll, the drafts' CRUD with their items, the status operations
 * (freeze, unfreeze, send, pay, cancel), the PDF, a preview of the next number and the counters.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class InvoiceController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = InvoiceResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = InvoiceCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreInvoiceRequest::class,
        'update' => UpdateInvoiceRequest::class,
        'destroy' => DestroyRequest::class,
        'freeze' => FreezeInvoiceRequest::class,
        'unfreeze' => UnfreezeInvoiceRequest::class,
        'send' => SendInvoiceRequest::class,
        'pay' => PayInvoiceRequest::class,
        'cancel' => CancelInvoiceRequest::class,
        'pdf' => ShowRequest::class,
        'previewNumber' => PreviewNumberRequest::class,
        'counters' => IndexRequest::class,
    ];

    /**
     * InvoiceController constructor.
     *
     * @param InvoicePolicy $policy
     * @param InvoiceRepository $repository
     * @param InvoiceFreezer $freezer
     * @param InvoiceLifecycle $lifecycle
     * @param InvoiceNumberGenerator $numbers
     * @param InvoicePdfRenderer $renderer
     */
    public function __construct(
        InvoicePolicy $policy,
        InvoiceRepository $repository,
        protected InvoiceFreezer $freezer,
        protected InvoiceLifecycle $lifecycle,
        protected InvoiceNumberGenerator $numbers,
        protected InvoicePdfRenderer $renderer
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `update` operation, refusing a stale write (`If-Unmodified-Since`).
     *
     * @param UpdateRequest $request
     *
     * @return ApiResponse
     * @throws Throwable
     */
    protected function handleUpdate(UpdateRequest $request): ApiResponse
    {
        /** @var UpdateInvoiceRequest $request */
        $request->ensureNotModified($this->builder($request)->findOrFail($request->getId()));

        return parent::handleUpdate($request);
    }

    /**
     * Handle the `freeze` operation.
     *
     * @param FreezeInvoiceRequest $request
     *
     * @return ApiResponse
     */
    protected function handleFreeze(FreezeInvoiceRequest $request): ApiResponse
    {
        $invoice = $this->freezer->freeze($this->profileOf($request), $this->find($request));

        return $this->asResourceResponse($this->fresh($invoice))->setMessage('Frozen');
    }

    /**
     * Handle the `unfreeze` operation.
     *
     * @param UnfreezeInvoiceRequest $request
     *
     * @return ApiResponse
     */
    protected function handleUnfreeze(UnfreezeInvoiceRequest $request): ApiResponse
    {
        $invoice = $this->freezer->unfreeze($this->profileOf($request), $this->find($request));

        return $this->asResourceResponse($this->fresh($invoice))->setMessage('Unfrozen');
    }

    /**
     * Handle the `send` operation.
     *
     * @param SendInvoiceRequest $request
     *
     * @return ApiResponse
     */
    protected function handleSend(SendInvoiceRequest $request): ApiResponse
    {
        $invoice = $this->lifecycle->send($this->profileOf($request), $this->find($request), $request->validated('sent_at'));

        return $this->asResourceResponse($this->fresh($invoice))->setMessage('Sent');
    }

    /**
     * Handle the `pay` operation.
     *
     * @param PayInvoiceRequest $request
     *
     * @return ApiResponse
     */
    protected function handlePay(PayInvoiceRequest $request): ApiResponse
    {
        $invoice = $this->lifecycle->pay($this->profileOf($request), $this->find($request), $request->validated('paid_at'));

        return $this->asResourceResponse($this->fresh($invoice))->setMessage('Paid');
    }

    /**
     * Handle the `cancel` operation.
     *
     * @param CancelInvoiceRequest $request
     *
     * @return ApiResponse
     */
    protected function handleCancel(CancelInvoiceRequest $request): ApiResponse
    {
        $invoice = $this->lifecycle->cancel($this->profileOf($request), $this->find($request), $request->validated('cancelled_at'));

        return $this->asResourceResponse($this->fresh($invoice))->setMessage('Cancelled');
    }

    /**
     * Download the invoice's primary document. A generated PDF, which is missing, is rendered again from the
     * snapshot.
     *
     * @param Request $request
     *
     * @return StreamedResponse
     * @throws Throwable
     */
    public function pdf(Request $request): StreamedResponse
    {
        /** @var ShowRequest $showRequest */
        $showRequest = $this->prepareRequest('pdf', $request);
        $invoice = $this->find($showRequest);
        $file = $invoice->primaryFile;

        if ((!$file || !$file->isStored()) && $invoice->snapshot !== null && $invoice->isGenerated()) {
            $file = $this->renderer->attachGenerated($invoice);
        }

        if (!$file || !$file->isStored()) {
            throw new NotFoundHttpException(__('The invoice has no document yet: freeze it, or upload its PDF.'));
        }

        return $file->download();
    }

    /**
     * Handle the `previewNumber` operation: the number, which a new invoice of the client and dates would get.
     *
     * @param PreviewNumberRequest $request
     *
     * @return ApiResponse
     */
    protected function handlePreviewNumber(PreviewNumberRequest $request): ApiResponse
    {
        $profile = $this->profileOf($request);
        $periodEnd = CarbonImmutable::parse($request->validated('period_end'));

        $draft = new Invoice;
        $draft->forceFill([
            'client_id' => Client::query()->findOrFail($request->validated('client_id'))->getKey(),
            'period_end' => $periodEnd,
            'issued_at' => CarbonImmutable::parse($request->validated('issued_at') ?? $periodEnd),
        ]);

        return ApiResponse::ok(['data' => [
            'number' => $this->numbers->preview($profile, $draft),
            'scope_key' => $this->numbers->scopeKey($profile, $draft),
        ]]);
    }

    /**
     * Handle the `counters` operation: the profile's invoice counters with their last numbers.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleCounters(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->numbers->counters($this->profileOf($request))->all()]);
    }

    /**
     * Find the invoice of the request.
     *
     * @param ShowRequest $request
     *
     * @return Invoice
     */
    protected function find(ShowRequest $request): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->builder($request)->findOrFail($request->getId());

        return $invoice;
    }

    /**
     * Get the invoice, as it is stored now, with its items.
     *
     * @param Invoice $invoice
     *
     * @return Invoice
     */
    protected function fresh(Invoice $invoice): Invoice
    {
        return Invoice::query()->withPaidAmount()->with('items')->findOrFail($invoice->getKey());
    }
}
