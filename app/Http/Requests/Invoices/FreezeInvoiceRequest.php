<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class FreezeInvoiceRequest.
 *
 * Freezes a draft invoice: allocates its number, takes its snapshot and renders its PDF.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class FreezeInvoiceRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'freeze';
}
