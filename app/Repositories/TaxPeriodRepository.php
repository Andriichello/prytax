<?php

namespace PryTax\Repositories;

use Carbon\CarbonImmutable;
use Core\Models\BaseModel;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\DeclarationStatus;
use PryTax\Http\Resources\TaxPeriodResource;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Alerts\AlertSync;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class TaxPeriodRepository.
 *
 * Repository of the tax periods (quarters) of a profile, newest first. A declaration marked submitted or accepted
 * locks its quarter; one returned to none, filled or rejected unlocks it; corrected keeps the lock (design.md §8.3).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class TaxPeriodRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = TaxPeriod::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = TaxPeriodResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['year' => 'desc', 'quarter' => 'desc'];

    /**
     * TaxPeriodRepository constructor.
     *
     * @param AlertSync $alerts
     */
    public function __construct(
        protected AlertSync $alerts
    ) {}

    /**
     * Update the tax period, locking or unlocking it by its declaration status.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        /** @var TaxPeriod $model */
        $from = $model->declaration_status;
        $model->fill($attributes);

        if ($model->isDirty('declaration_status')) {
            $status = $model->declaration_status;
            $this->assertTransition($model, $from, $status);

            if ($status === DeclarationStatus::Submitted) {
                $model->locked_at = now();
                $model->declaration_submitted_at = $attributes['declaration_submitted_at'] ?? now();
            } elseif ($status === DeclarationStatus::Accepted) {
                $model->locked_at ??= now();
            } else {
                $model->locked_at = null;
            }
        }

        $saved = $model->save();

        $this->alerts->syncAfterCommit($this->profile());

        return $saved;
    }

    /**
     * Make sure the declaration's status may change so: a declaration is filled, submitted, then accepted or
     * rejected; a rejected one is filled or submitted again; an accepted one changes only by a correction (PeriodLock),
     * after which the уточнююча декларація is submitted. A declaration is submitted or accepted after its quarter
     * ends only.
     *
     * @param TaxPeriod $period
     * @param DeclarationStatus $from
     * @param DeclarationStatus $to
     *
     * @return void
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    protected function assertTransition(TaxPeriod $period, DeclarationStatus $from, DeclarationStatus $to): void
    {
        $allowed = [
            DeclarationStatus::None->value => [DeclarationStatus::Filled, DeclarationStatus::Submitted],
            DeclarationStatus::Filled->value => [DeclarationStatus::None, DeclarationStatus::Submitted],
            DeclarationStatus::Submitted->value => [DeclarationStatus::Accepted, DeclarationStatus::Rejected],
            DeclarationStatus::Rejected->value => [DeclarationStatus::Filled, DeclarationStatus::Submitted],
            DeclarationStatus::Corrected->value => [DeclarationStatus::Submitted],
        ];

        if (!in_array($to, $allowed[$from->value] ?? [], true)) {
            throw new ConflictHttpException(__('The declaration can\'t change from :from to :to.', ['from' => $from->value, 'to' => $to->value]));
        }

        $ended = CarbonImmutable::create($period->year, $period->quarter * 3)->endOfMonth()->lt(CarbonImmutable::today());

        if (!$ended && in_array($to, [DeclarationStatus::Submitted, DeclarationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'declaration_status' => [__('A declaration is submitted after its quarter ends.')],
            ]);
        }
    }
}
