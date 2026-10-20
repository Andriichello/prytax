<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class UnfreezeInvoiceRequest.
 *
 * Returns a ready invoice without receipts to its draft.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class UnfreezeInvoiceRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'unfreeze';
}
