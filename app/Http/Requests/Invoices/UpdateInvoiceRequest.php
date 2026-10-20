<?php

namespace PryTax\Http\Requests\Invoices;

use Carbon\CarbonImmutable;
use Core\Http\Requests\Crud\UpdateRequest;
use PryTax\Models\Invoice;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Class UpdateInvoiceRequest.
 *
 * Updates a draft invoice (its items, when given, replace its own), or the notes of a frozen one. With
 * `If-Unmodified-Since` (the invoice's `updated_at`), a stale write, such as two accountants editing one invoice,
 * is refused with 409 (design.md §10).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class UpdateInvoiceRequest extends UpdateRequest
{
    use InvoiceRules;

    /**
     * Header, which carries the `updated_at` the client edited.
     *
     * @var string
     */
    public const string HEADER = 'If-Unmodified-Since';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->invoiceRules('sometimes');
    }

    /**
     * Make sure the invoice wasn't changed since the client read it, when the client says when that was.
     *
     * @param Invoice $invoice
     *
     * @return void
     * @throws ConflictHttpException
     */
    public function ensureNotModified(Invoice $invoice): void
    {
        $header = $this->header(static::HEADER);

        if ($header === null || $header === '') {
            return;
        }

        try {
            $since = CarbonImmutable::parse($header);
        } catch (Throwable) {
            throw new PreconditionFailedHttpException(static::HEADER . ' must be a date and time.');
        }

        if ($invoice->updated_at !== null && $invoice->updated_at->getTimestamp() > $since->getTimestamp()) {
            throw new ConflictHttpException(
                __('The invoice was changed by someone else. Reload it and try again.')
            );
        }
    }
}
