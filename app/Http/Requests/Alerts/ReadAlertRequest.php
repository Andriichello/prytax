<?php

namespace PryTax\Http\Requests\Alerts;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class ReadAlertRequest.
 *
 * Marks a notification as read by the signed-in user: the client sends it, when the user taps the notification,
 * its action or its ✓, and never when the list is shown (design.md §9).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Alerts
 */
class ReadAlertRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'read';
}
