<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class IssueInvoiceRequest.
 *
 * Issues the virtual invoice of a payment without an invoice as a generated one (design.md §4.2): numbered, with its
 * item, snapshot and PDF, and still paid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class IssueInvoiceRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'issue';
}
