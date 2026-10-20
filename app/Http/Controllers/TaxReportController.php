<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Reports\TaxReportRequest;
use PryTax\Http\Resources\TaxPaymentCollection;
use PryTax\Http\Resources\TaxPaymentResource;
use PryTax\Policies\TaxPaymentPolicy;
use PryTax\Repositories\TaxPaymentRepository;
use PryTax\Services\Reports\DeclarationFigures;
use PryTax\Services\Reports\TaxBreakdown;
use PryTax\Services\Taxes\TaxReconciler;

/**
 * Class TaxReportController.
 *
 * Податки of a profile (design.md §8): a tax's quarters with owed, paid, pending and the running balance
 * (`taxes/{year}?tax_type=`), ЄП and ВЗ by receipt (`taxes/{year}/{quarter}/payments`) and by month
 * (`taxes/{year}/months`), and a quarter's declaration figures (`declarations/{year}/{quarter}`). Reading them needs
 * the `viewAny models.tax-payments` permission.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class TaxReportController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = TaxPaymentResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = TaxPaymentCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'reconciliation' => TaxReportRequest::class,
        'byPayment' => IndexRequest::class,
        'byMonth' => IndexRequest::class,
        'declaration' => IndexRequest::class,
    ];

    /**
     * TaxReportController constructor.
     *
     * @param TaxPaymentPolicy $policy
     * @param TaxPaymentRepository $repository
     * @param TaxReconciler $reconciler
     * @param TaxBreakdown $breakdown
     * @param DeclarationFigures $declarations
     */
    public function __construct(
        TaxPaymentPolicy $policy,
        TaxPaymentRepository $repository,
        protected TaxReconciler $reconciler,
        protected TaxBreakdown $breakdown,
        protected DeclarationFigures $declarations
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `reconciliation` operation.
     *
     * @param TaxReportRequest $request
     *
     * @return ApiResponse
     */
    protected function handleReconciliation(TaxReportRequest $request): ApiResponse
    {
        $type = TaxType::from($request->validated('tax_type') ?? TaxType::SingleTax->value);

        return ApiResponse::ok(['data' => $this->reconciler->forYear($this->profileOf($request), $type, (int) $request->route('year'))]);
    }

    /**
     * Handle the `byPayment` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleByPayment(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->breakdown->byPayment(
            $this->profileOf($request),
            (int) $request->route('year'),
            (int) $request->route('quarter'),
        )]);
    }

    /**
     * Handle the `byMonth` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleByMonth(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->breakdown->byMonth($this->profileOf($request), (int) $request->route('year'))]);
    }

    /**
     * Handle the `declaration` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDeclaration(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->declarations->forQuarter(
            $this->profileOf($request),
            (int) $request->route('year'),
            (int) $request->route('quarter'),
        )]);
    }
}
