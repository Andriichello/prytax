<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Queries\BaseQuery;
use Illuminate\Http\Request;
use PryTax\Http\Filters\PeriodFilter;
use PryTax\Http\Filters\StatusFilter;
use PryTax\Http\Resources\InvoiceResource;
use PryTax\Models\Invoice;
use PryTax\Services\Invoices\InvoiceWriter;

/**
 * Class InvoiceRepository.
 *
 * Repository of the invoices of a profile, newest issued first, each with what its receipts cover (`paid_amount`).
 * Lists are filtered by `period` (years, quarters, months), or a range of `issued_at`, and by `client_id`,
 * `currency` and `status` (design.md §10); drafts are written by InvoiceWriter.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class InvoiceRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Invoice::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = InvoiceResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['issued_at' => 'desc', 'id' => 'desc'];

    /**
     * Filters, besides the fillable columns.
     *
     * @var array
     */
    protected array $allowedFilters = [
        'period' => PeriodFilter::class,
        'status' => StatusFilter::class,
        'locked_at',
    ];

    /**
     * InvoiceRepository constructor.
     *
     * @param InvoiceWriter $writer
     */
    public function __construct(
        protected InvoiceWriter $writer
    ) {}

    /**
     * Get the query builder for the request, with `paid_amount`.
     *
     * @param Request|null $request
     *
     * @return BaseQuery
     */
    public function builder(?Request $request = null): BaseQuery
    {
        return parent::builder($request)->withPaidAmount();
    }

    /**
     * Create a draft invoice with its items.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        return $this->writer->create($parents['profile'] ?? $this->profile(), $attributes);
    }

    /**
     * Update the invoice: a draft with its items, or the notes of a frozen one.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        $this->writer->update($this->profile(), $model, $attributes);

        return true;
    }
}
