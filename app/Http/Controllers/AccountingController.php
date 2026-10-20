<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PryTax\Http\Resources\PaymentCollection;
use PryTax\Http\Resources\PaymentResource;
use PryTax\Policies\PaymentPolicy;
use PryTax\Repositories\PaymentRepository;
use PryTax\Services\Reports\AccountingExporter;
use PryTax\Services\Reports\AccountingView;
use PryTax\Services\Reports\EsvTable;
use PryTax\Services\Reports\FeeStatistics;
use PryTax\Services\Reports\IncomeBook;
use Throwable;

/**
 * Class AccountingController.
 *
 * Облік of a profile (design.md §7): a quarter's receipts with its summary (`accounting/{year}/{quarter}`), the
 * year's ЄСВ (`esv/{year}`), income book (`income-book/{year}`) and costs of its money paths (`fees/{year}`), and the
 * CSV exports of the quarter, of the year's receipts and taxes (`accounting/{year}/export`) and of the income book.
 * Reading them needs the `viewAny models.payments` permission.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AccountingController extends TenantController
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
        'quarter' => IndexRequest::class,
        'exportQuarter' => IndexRequest::class,
        'exportYear' => IndexRequest::class,
        'fees' => IndexRequest::class,
        'esv' => IndexRequest::class,
        'incomeBook' => IndexRequest::class,
        'exportIncomeBook' => IndexRequest::class,
    ];

    /**
     * AccountingController constructor.
     *
     * @param PaymentPolicy $policy
     * @param PaymentRepository $repository
     * @param AccountingView $accounting
     * @param IncomeBook $incomeBook
     * @param EsvTable $esvTable
     * @param AccountingExporter $exporter
     * @param FeeStatistics $fees
     */
    public function __construct(
        PaymentPolicy $policy,
        PaymentRepository $repository,
        protected AccountingView $accounting,
        protected IncomeBook $incomeBook,
        protected EsvTable $esvTable,
        protected AccountingExporter $exporter,
        protected FeeStatistics $fees
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `quarter` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleQuarter(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->accounting->forQuarter(
            $this->profileOf($request),
            (int) $request->route('year'),
            (int) $request->route('quarter'),
        )]);
    }

    /**
     * Handle the `esv` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleEsv(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->esvTable->forYear($this->profileOf($request), (int) $request->route('year'))]);
    }

    /**
     * Handle the `incomeBook` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleIncomeBook(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->incomeBook->forYear($this->profileOf($request), (int) $request->route('year'))]);
    }

    /**
     * Download the CSV of a quarter's receipts.
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function exportQuarter(Request $request): Response
    {
        /** @var IndexRequest $index */
        $index = $this->prepareRequest('exportQuarter', $request);
        $year = (int) $request->route('year');
        $quarter = (int) $request->route('quarter');

        return $this->csv(
            $this->exporter->quarter($this->profileOf($index), $year, $quarter),
            "oblik-$year-q$quarter.csv"
        );
    }

    /**
     * Handle the `fees` operation: what the money paths cost in the year, by quarter, account, client and route, with
     * each quarter's net income.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleFees(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->fees->forYear($this->profileOf($request), (int) $request->route('year'))]);
    }

    /**
     * Download the CSV of the year's receipts and taxes.
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function exportYear(Request $request): Response
    {
        /** @var IndexRequest $index */
        $index = $this->prepareRequest('exportYear', $request);
        $year = (int) $request->route('year');

        return $this->csv($this->exporter->year($this->profileOf($index), $year), "nadkhodzhennia-ta-podatky-$year.csv");
    }

    /**
     * Download the CSV of a year's income book.
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function exportIncomeBook(Request $request): Response
    {
        /** @var IndexRequest $index */
        $index = $this->prepareRequest('exportIncomeBook', $request);
        $year = (int) $request->route('year');

        return $this->csv($this->exporter->incomeBook($this->profileOf($index), $year), "knyha-dokhodiv-$year.csv");
    }

    /**
     * Answer the CSV as a download.
     *
     * @param string $csv
     * @param string $name
     *
     * @return Response
     */
    protected function csv(string $csv, string $name): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$name\"",
        ]);
    }
}
