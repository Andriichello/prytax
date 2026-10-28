<?php

namespace PryTax\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PryTax\Helpers\MoneyFormatter;
use PryTax\Http\Resources\PaymentCollection;
use PryTax\Http\Resources\PaymentResource;
use PryTax\Policies\PaymentPolicy;
use PryTax\Repositories\PaymentRepository;
use PryTax\Services\Reports\AccountingBooks;
use Throwable;

/**
 * Class BooksController.
 *
 * Книги обліку of a profile (design.md §16.7): the income book of a year (`books/{year}/income`), its taxes
 * (`books/{year}/taxes`), and each of them as a PDF (`books/{year}/{book}/export?format=pdf`), a portrait A4 table,
 * which mirrors the screen. Reading them needs the `viewAny models.payments` permission, as Облік does.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class BooksController extends TenantController
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
        'income' => IndexRequest::class,
        'taxes' => IndexRequest::class,
        'export' => IndexRequest::class,
    ];

    /**
     * BooksController constructor.
     *
     * @param PaymentPolicy $policy
     * @param PaymentRepository $repository
     * @param AccountingBooks $books
     */
    public function __construct(
        PaymentPolicy $policy,
        PaymentRepository $repository,
        protected AccountingBooks $books
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `income` operation: the income book of the year.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleIncome(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->books->income($this->profileOf($request), (int) $request->route('year'))]);
    }

    /**
     * Handle the `taxes` operation: the taxes of the year.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleTaxes(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->books->taxes($this->profileOf($request), (int) $request->route('year'))]);
    }

    /**
     * Download a book of the year as a PDF.
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function export(Request $request): Response
    {
        /** @var IndexRequest $index */
        $index = $this->prepareRequest('export', $request);
        $profile = $this->profileOf($index);
        $year = (int) $request->route('year');
        $book = (string) $request->route('book');

        $pdf = Pdf::loadView("pdf.books.$book", [
            'profile' => $profile,
            'year' => $year,
            'book' => $book === 'income' ? $this->books->income($profile, $year) : $this->books->taxes($profile, $year),
            'money' => MoneyFormatter::of($profile->setting('display.money_format')),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        $pdf->render();
        $this->numberPages($pdf->getDomPDF()->getCanvas());

        $name = $book === 'income' ? "knyha-dokhodiv-$year.pdf" : "podatky-$year.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"$name\"",
        ]);
    }

    /**
     * Number every page at its bottom right: "1 / 3", in DejaVu Sans Mono.
     *
     * @param Canvas $canvas
     *
     * @return void
     */
    protected function numberPages(Canvas $canvas): void
    {
        $canvas->page_script(function (int $page, int $total, Canvas $canvas, FontMetrics $metrics) {
            $font = $metrics->getFont('DejaVu Sans Mono');
            $text = "$page / $total";

            $canvas->text($canvas->get_width() - 40 - $metrics->getTextWidth($text, $font, 7), $canvas->get_height() - 30, $text, $font, 7, [0.35, 0.37, 0.42]);
        });
    }
}
