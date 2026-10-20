<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Crud\StoreRequest;
use Core\Http\Requests\Crud\UpdateRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Http\Requests\Payments\AcknowledgeRepatriationRequest;
use PryTax\Http\Requests\Payments\GroupPaymentsRequest;
use PryTax\Http\Requests\Payments\PreviewPaymentRequest;
use PryTax\Http\Requests\Payments\StorePaymentRequest;
use PryTax\Http\Requests\Payments\UpdatePaymentRequest;
use PryTax\Http\Resources\PaymentCollection;
use PryTax\Http\Resources\PaymentResource;
use PryTax\Models\Payment;
use PryTax\Policies\PaymentPolicy;
use PryTax\Repositories\PaymentRepository;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Payments\PaymentFlow;
use PryTax\Services\Payments\PaymentRecorder;

/**
 * Class PaymentController.
 *
 * Receipts of a profile: `profiles/{profile}/payments`, filtered by `invoice_id`, `kind`, `period_year`,
 * `period_quarter` and more. Creating one answers how it covers its invoice (`meta.invoice`), so the form suggests
 * marking it paid. Also one wire, which covers several invoices (`payments/group`), a preview of a receipt before
 * saving it, the path of its money, and dismissing its repatriation notice. POSTs take an `Idempotency-Key`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class PaymentController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = PaymentResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = PaymentCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StorePaymentRequest::class,
        'update' => UpdatePaymentRequest::class,
        'destroy' => DestroyRequest::class,
        'preview' => PreviewPaymentRequest::class,
        'group' => GroupPaymentsRequest::class,
        'flow' => ShowRequest::class,
        'acknowledge' => AcknowledgeRepatriationRequest::class,
    ];

    /**
     * PaymentController constructor.
     *
     * @param PaymentPolicy $policy
     * @param PaymentRepository $repository
     * @param PaymentRecorder $recorder
     * @param PaymentFlow $flows
     * @param AlertSync $alerts
     */
    public function __construct(
        PaymentPolicy $policy,
        PaymentRepository $repository,
        protected PaymentRecorder $recorder,
        protected PaymentFlow $flows,
        protected AlertSync $alerts
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `store` operation: record the receipt, and tell how it covers its invoice.
     *
     * @param StoreRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStore(StoreRequest $request): ApiResponse
    {
        /** @var Payment $payment */
        $payment = $this->repository->create($request->validated(), $request->getParents());

        return ApiResponse::created([
            'data' => $this->asResource($payment)->resolve($request),
            'meta' => ['invoice' => $this->recorder->coverage($payment->invoice)],
        ]);
    }

    /**
     * Handle the `update` operation: update the receipt, and tell how it covers its invoice.
     *
     * @param UpdateRequest $request
     *
     * @return ApiResponse
     */
    protected function handleUpdate(UpdateRequest $request): ApiResponse
    {
        /** @var Payment $payment */
        $payment = $this->builder($request)->findOrFail($request->getId());
        $this->repository->update($payment, $request->validated());
        $payment->refresh();

        return ApiResponse::ok([
            'data' => $this->asResource($payment)->resolve($request),
            'meta' => ['invoice' => $this->recorder->coverage($payment->invoice)],
        ]);
    }

    /**
     * Handle the `group` operation: record one wire, which covers several invoices, and tell how it covers each.
     *
     * @param GroupPaymentsRequest $request
     *
     * @return ApiResponse
     */
    protected function handleGroup(GroupPaymentsRequest $request): ApiResponse
    {
        $payments = $this->recorder->recordGroup($this->profileOf($request), $request->validated());

        return ApiResponse::created([
            'data' => $payments->map(fn (Payment $payment) => $this->asResource($payment)->resolve($request))->all(),
            'meta' => ['invoices' => $payments->map(fn (Payment $payment) => $this->recorder->coverage($payment->invoice))->all()],
        ]);
    }

    /**
     * Handle the `preview` operation: what the receipt would be saved with.
     *
     * @param PreviewPaymentRequest $request
     *
     * @return ApiResponse
     */
    protected function handlePreview(PreviewPaymentRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->recorder->preview($this->profileOf($request), $request->validated())]);
    }

    /**
     * Handle the `flow` operation: the path of the receipt's money, and its fees, landing and net income.
     *
     * @param ShowRequest $request
     *
     * @return ApiResponse
     */
    protected function handleFlow(ShowRequest $request): ApiResponse
    {
        /** @var Payment $payment */
        $payment = $this->builder($request)->findOrFail($request->getId());

        return ApiResponse::ok(['data' => $this->flows->forPayment($payment)]);
    }

    /**
     * Handle the `acknowledge` operation: dismiss the receipt's repatriation notice.
     *
     * @param AcknowledgeRepatriationRequest $request
     *
     * @return ApiResponse
     */
    protected function handleAcknowledge(AcknowledgeRepatriationRequest $request): ApiResponse
    {
        /** @var Payment $payment */
        $payment = $this->builder($request)->findOrFail($request->getId());
        $payment->forceFill(['repatriation_ack_at' => now()])->save();

        $this->alerts->syncAfterCommit($this->profileOf($request));

        return $this->asResourceResponse($payment->fresh())->setMessage('Acknowledged');
    }
}
