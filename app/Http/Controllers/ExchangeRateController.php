<?php

namespace PryTax\Http\Controllers;

use Carbon\CarbonImmutable;
use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\StoreRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Validation\ValidationException;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Http\Requests\ExchangeRates\StoreExchangeRateRequest;
use PryTax\Http\Resources\ExchangeRateCollection;
use PryTax\Http\Resources\ExchangeRateResource;
use PryTax\Policies\ExchangeRatePolicy;
use PryTax\Repositories\ExchangeRateRepository;
use PryTax\Services\Rates\NbuRateProvider;
use Throwable;

/**
 * Class ExchangeRateController.
 *
 * NBU rates (`exchange-rates/{currency}/{date}`), fetched and cached when missing, which answers 422 when the NBU
 * can't be reached, so the form offers a manual rate (`POST exchange-rates`). The cached rates are listed too.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ExchangeRateController extends CrudController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = ExchangeRateResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = ExchangeRateCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'lookup' => IndexRequest::class,
        'store' => StoreExchangeRateRequest::class,
    ];

    /**
     * ExchangeRateController constructor.
     *
     * @param ExchangeRatePolicy $policy
     * @param ExchangeRateRepository $repository
     * @param NbuRateProvider $rates
     */
    public function __construct(
        ExchangeRatePolicy $policy,
        ExchangeRateRepository $repository,
        protected NbuRateProvider $rates
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `lookup` operation: the rate of the currency on the date.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     * @throws ValidationException
     */
    protected function handleLookup(IndexRequest $request): ApiResponse
    {
        $currency = strtoupper((string) $request->route('currency'));

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->route('date'));
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $currency === 'UAH') {
            throw ValidationException::withMessages(['date' => [__('Give a foreign currency and a date, such as USD/2026-10-05.')]]);
        }

        try {
            $rate = $this->rates->rate($currency, $date);
        } catch (ExchangeRateUnavailableException $exception) {
            throw ValidationException::withMessages(['rate' => [$exception->getMessage()]]);
        }

        return $this->asResourceResponse($rate);
    }

    /**
     * Handle the `store` operation: a manual rate.
     *
     * @param StoreRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStore(StoreRequest $request): ApiResponse
    {
        $rate = $this->rates->manual(
            $request->validated('currency'),
            CarbonImmutable::parse($request->validated('rate_date')),
            $request->validated('rate'),
        );

        return $this->asResourceResponse($rate)->setMessage('Created')->setStatus(201);
    }
}
