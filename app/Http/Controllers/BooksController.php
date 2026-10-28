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
use PryTax\Services\Pdf\PdfFonts;
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
     * @param PdfFonts $fonts
     */
    public function __construct(
        PaymentPolicy $policy,
        PaymentRepository $repository,
        protected AccountingBooks $books,
        protected PdfFonts $fonts
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

        $this->fonts->metrics();
        $title = $book === 'income' ? __('Income book · :year', ['year' => $year]) : __('Taxes · :year', ['year' => $year]);
        $pdf = Pdf::loadView("pdf.books.$book", [
            'profile' => $profile,
            'year' => $year,
            'book' => $book === 'income' ? $this->books->income($profile, $year) : $this->books->taxes($profile, $year),
            'money' => MoneyFormatter::of($profile->setting('display.money_format')),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        $pdf->render();
        $this->numberPages($pdf->getDomPDF()->getCanvas(), $title);

        $name = $book === 'income' ? "knyha-dokhodiv-$year.pdf" : "podatky-$year.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"$name\"",
        ]);
    }

    /**
     * Put the book's name and the page's number at the foot of each page, as the app's muted small print: «PryTax ·
     * Книга обліку доходів · 2026» on the left, «1 / 2» on the right.
     *
     * @param Canvas $canvas
     * @param string $title
     *
     * @return void
     */
    protected function numberPages(Canvas $canvas, string $title): void
    {
        $canvas->page_script(function (int $page, int $total, Canvas $canvas, FontMetrics $metrics) use ($title) {
            $text = $metrics->getFont('DejaVu Sans');
            $figures = $metrics->getFont('Plex Mono') ?? $text;
            $number = "$page / $total";
            $color = [0.557, 0.573, 0.612];
            $y = $canvas->get_height() - 30;

            $canvas->text(40, $y, "PryTax · $title", $text, 7, $color);
            $canvas->text($canvas->get_width() - 40 - $metrics->getTextWidth($number, $figures, 7), $y, $number, $figures, 7, $color);
        });
    }
}
