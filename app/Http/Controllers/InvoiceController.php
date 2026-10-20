<?php

namespace PryTax\Http\Controllers;

use Carbon\CarbonImmutable;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Crud\UpdateRequest;
use Core\Http\Responses\ApiResponse;
use Core\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PryTax\Helpers\Decimal;
use PryTax\Http\Requests\Invoices\CancelInvoiceRequest;
use PryTax\Http\Requests\Invoices\FindDuplicateFilesRequest;
use PryTax\Http\Requests\Invoices\FreezeInvoiceRequest;
use PryTax\Http\Requests\Invoices\InvoiceDefaultsRequest;
use PryTax\Http\Requests\Invoices\PayInvoiceRequest;
use PryTax\Http\Requests\Invoices\PreviewNumberRequest;
use PryTax\Http\Requests\Invoices\SendInvoiceRequest;
use PryTax\Http\Requests\Invoices\SetCounterRequest;
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
use PryTax\Services\Invoices\InvoiceWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Class InvoiceController.
 *
 * Invoices of a profile: `profiles/{profile}/invoices`, a list filtered by `period`, `issued_at`, `client_id`,
 * `currency` and `status` and paged for infinite scroll, the drafts' CRUD with their items, the status operations
 * (freeze, unfreeze, send, pay, cancel), the PDF, a preview of the next number and the counters. For the screens:
 * what a new invoice's form starts with, the open invoices' totals of Головна, the years, currencies, clients and
 * statuses the filters offer, and the invoices, which have a file of a SHA-256 already.
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
        'setCounter' => SetCounterRequest::class,
        'defaults' => InvoiceDefaultsRequest::class,
        'outstanding' => IndexRequest::class,
        'facets' => IndexRequest::class,
        'duplicates' => FindDuplicateFilesRequest::class,
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
     * @param InvoiceWriter $writer
     */
    public function __construct(
        InvoicePolicy $policy,
        InvoiceRepository $repository,
        protected InvoiceFreezer $freezer,
        protected InvoiceLifecycle $lifecycle,
        protected InvoiceNumberGenerator $numbers,
        protected InvoicePdfRenderer $renderer,
        protected InvoiceWriter $writer
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
        return DB::transaction(function () use ($request) {
            // Locked until the write ends, so two writes, which read the same updated_at, can't both pass
            $request->ensureNotModified(Invoice::query()->lockForUpdate()->findOrFail($request->getId()));

            return parent::handleUpdate($request);
        });
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

        // Only a generated PDF is rendered again: an uploaded primary document is never replaced
        $generated = $file === null || $file->collection === Invoice::GENERATED_COLLECTION;

        if ((!$file || !$file->isStored()) && $generated && $invoice->snapshot !== null && $invoice->isGenerated()) {
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
     * Handle the `setCounter` operation: move a counter forward to the next number it allocates.
     *
     * @param SetCounterRequest $request
     *
     * @return ApiResponse
     */
    protected function handleSetCounter(SetCounterRequest $request): ApiResponse
    {
        $profile = $this->profileOf($request);
        $this->numbers->setNext($profile, $request->validated('scope_key'), (int) $request->validated('next_number'));

        return ApiResponse::ok(['data' => $this->numbers->counters($profile)->all()]);
    }

    /**
     * Handle the `defaults` operation: what the form of a new invoice of the client starts with.
     *
     * @param InvoiceDefaultsRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDefaults(InvoiceDefaultsRequest $request): ApiResponse
    {
        $data = $request->validated();
        $defaults = $this->writer->formDefaults($data);

        $draft = new Invoice;
        $draft->forceFill([
            'client_id' => $data['client_id'],
            'period_end' => CarbonImmutable::parse($data['period_end'] ?? $defaults['issued_at'] ?? 'today'),
            'issued_at' => CarbonImmutable::parse($defaults['issued_at'] ?? 'today'),
        ]);

        return ApiResponse::ok(['data' => [
            ...$defaults,
            'number_preview' => $this->numbers->preview($this->profileOf($request), $draft),
        ]]);
    }

    /**
     * Handle the `outstanding` operation: what the open invoices (ready and sent) leave to pay, per currency, with
     * how much of it is overdue, for "X до оплати" on Головна.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleOutstanding(IndexRequest $request): ApiResponse
    {
        $today = CarbonImmutable::today();

        $totals = Invoice::query()->open()->withPaidAmount()->get()
            ->groupBy('currency')
            ->map(function ($invoices, string $currency) use ($today) {
                $left = fn (Invoice $invoice) => Decimal::max(Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount)), 0);
                $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->due_at?->lt($today) && $left($invoice)->isPositive());

                return [
                    'currency' => $currency,
                    'count' => $invoices->count(),
                    'total' => Decimal::money(Decimal::sum($invoices->pluck('total'))),
                    'paid' => Decimal::money(Decimal::sum($invoices->pluck('paid_amount'))),
                    'remaining' => Decimal::money(Decimal::sum($invoices->map($left))),
                    'overdue_count' => $overdue->count(),
                    'overdue' => Decimal::money(Decimal::sum($overdue->map($left))),
                ];
            })
            ->sortKeys()
            ->values();

        return ApiResponse::ok(['data' => $totals->all()]);
    }

    /**
     * Handle the `facets` operation: the years, currencies, clients and statuses of the profile's invoices, which
     * the filters offer, with their counts.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleFacets(IndexRequest $request): ApiResponse
    {
        $count = fn (string $expression, string $as) => Invoice::query()
            ->selectRaw("$expression as $as, COUNT(*) as count")
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->map(fn ($row) => [$as => $row->$as, 'count' => (int) $row->count])
            ->values()
            ->all();

        $clients = Invoice::query()
            ->selectRaw('client_id, COUNT(*) as count')
            ->groupBy('client_id')
            ->with('client:id,name')
            ->get()
            ->map(fn (Invoice $row) => ['client_id' => $row->client_id, 'name' => $row->client?->name, 'count' => (int) $row->getAttribute('count')])
            ->sortBy('name')
            ->values()
            ->all();

        return ApiResponse::ok(['data' => [
            'years' => array_map(fn (array $year) => [...$year, 'year' => (int) $year['year']], $count('YEAR(issued_at)', 'year')),
            'currencies' => $count('currency', 'currency'),
            'statuses' => $count('status', 'status'),
            'clients' => $clients,
        ]]);
    }

    /**
     * Handle the `duplicates` operation: the invoices of the profile, which have a file of the SHA-256 already, so
     * an external PDF is checked before its invoice is created.
     *
     * @param FindDuplicateFilesRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDuplicates(FindDuplicateFilesRequest $request): ApiResponse
    {
        $ids = File::query()
            ->where('fileable_type', (new Invoice)->getMorphClass())
            ->where('sha256', $request->validated('sha256'))
            ->pluck('fileable_id');

        $invoices = Invoice::query()->whereKey($ids)->with('client:id,name')->orderByDesc('issued_at')->get()
            ->map(fn (Invoice $invoice) => [
                'invoice_id' => $invoice->getKey(),
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'client_name' => $invoice->client?->name,
                'issued_at' => $invoice->issued_at->toDateString(),
            ])
            ->values();

        return ApiResponse::ok(['data' => $invoices->all()]);
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
