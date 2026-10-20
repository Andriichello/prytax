<?php

namespace PryTax\Http\Controllers;

use Carbon\CarbonImmutable;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Http\Requests\Taxes\CurrentRequisiteRequest;
use PryTax\Http\Requests\Taxes\StoreTaxPaymentRequisiteRequest;
use PryTax\Http\Requests\Taxes\UpdateTaxPaymentRequisiteRequest;
use PryTax\Http\Resources\TaxPaymentRequisiteCollection;
use PryTax\Http\Resources\TaxPaymentRequisiteResource;
use PryTax\Models\TaxPaymentRequisite;
use PryTax\Policies\ProfilePolicy;
use PryTax\Policies\TaxPaymentRequisitePolicy;
use PryTax\Repositories\TaxPaymentRequisiteRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class TaxPaymentRequisiteController.
 *
 * Budget requisites of the taxes of a profile, and the one of a tax in effect on a date, with the purpose of a payment
 * for a quarter or a month filled in (`tax-payment-requisites/current`): `{tax_id}`, `{full_name}` and `{period}`,
 * such as "III квартал 2026 року" or "жовтень 2026 року". Users, who can't configure the profile (viewers), get the
 * tax id masked in the purpose, as everywhere else.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class TaxPaymentRequisiteController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = TaxPaymentRequisiteResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = TaxPaymentRequisiteCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreTaxPaymentRequisiteRequest::class,
        'update' => UpdateTaxPaymentRequisiteRequest::class,
        'destroy' => DestroyRequest::class,
        'current' => CurrentRequisiteRequest::class,
    ];

    /**
     * Months in the nominative case, for the purpose of a payment.
     *
     * @var array<int, string>
     */
    public const array MONTHS = [
        1 => 'січень', 2 => 'лютий', 3 => 'березень', 4 => 'квітень', 5 => 'травень', 6 => 'червень',
        7 => 'липень', 8 => 'серпень', 9 => 'вересень', 10 => 'жовтень', 11 => 'листопад', 12 => 'грудень',
    ];

    /**
     * TaxPaymentRequisiteController constructor.
     *
     * @param TaxPaymentRequisitePolicy $policy
     * @param TaxPaymentRequisiteRepository $repository
     */
    public function __construct(TaxPaymentRequisitePolicy $policy, TaxPaymentRequisiteRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `current` operation: the requisites of the tax in effect on the date, with the payment's purpose.
     *
     * @param CurrentRequisiteRequest $request
     *
     * @return ApiResponse
     */
    protected function handleCurrent(CurrentRequisiteRequest $request): ApiResponse
    {
        $profile = $this->profileOf($request);
        $data = $request->validated();
        $date = CarbonImmutable::parse($data['date'] ?? 'today');

        $requisite = TaxPaymentRequisite::query()
            ->where('tax_type', $data['tax_type'])
            ->where('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if (!$requisite) {
            throw new NotFoundHttpException(__('The requisites of the tax aren\'t entered yet.'));
        }

        $period = empty($data['period_month'])
            ? ['I', 'II', 'III', 'IV'][$data['period_quarter'] - 1] . " квартал {$data['period_year']} року"
            : self::MONTHS[(int) $data['period_month']] . " {$data['period_year']} року";

        // The full tax id only for users, who may see it (ProfileResource masks it so for the others)
        $taxId = app(ProfilePolicy::class)->canConfigure($request->user(), $profile)
            ? (string) $profile->tax_id
            : $profile->tax_id_masked;

        $purpose = strtr($requisite->purpose_template, [
            '{tax_id}' => $taxId,
            '{full_name}' => (string) $profile->full_name,
            '{period}' => $period,
        ]);

        return ApiResponse::ok(['data' => [...$this->asResource($requisite)->resolve($request), 'purpose' => $purpose]]);
    }
}
