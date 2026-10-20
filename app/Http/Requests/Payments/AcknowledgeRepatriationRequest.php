<?php

namespace PryTax\Http\Requests\Payments;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class AcknowledgeRepatriationRequest.
 *
 * Dismisses the notice of a receipt, whose money stays on a platform past the repatriation rule (design.md §6.7).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
class AcknowledgeRepatriationRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'acknowledge';
}
