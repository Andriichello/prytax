<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Http\Resources\InvoiceResource;
use PryTax\Models\Integration;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class GetInvoice.
 *
 * One invoice of a ФОП, by its id or number: its client, contract, items and payments.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get one invoice of a ФОП by its id or number, with its client, contract, items, totals, paid amount and the payments that settled it.')]
class GetInvoice extends ReadTool
{
    /**
     * The model, whose policy authorizes the read.
     *
     * @var class-string
     */
    protected string $model = Invoice::class;

    /**
     * Get the validation rules of the tool's own arguments.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return ['invoice' => ['required', 'regex:/^[^\n]{1,64}$/u']];
    }

    /**
     * Get the schema of the tool's own arguments.
     *
     * @param JsonSchema $schema
     *
     * @return array<string, mixed>
     */
    protected function arguments(JsonSchema $schema): array
    {
        return ['invoice' => $schema->string()->required()->description('The invoice\'s id, or its number, such as BL-2026-08-31-11.')];
    }

    /**
     * Read the invoice.
     *
     * @param array<string, mixed> $arguments
     * @param User $user
     * @param Integration $integration
     * @param Profile|null $profile
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    protected function read(array $arguments, User $user, Integration $integration, ?Profile $profile): array
    {
        $value = trim((string) $arguments['invoice']);
        $invoice = Invoice::query()
            ->withPaidAmount()
            ->with(['client', 'contract', 'items', 'payments'])
            ->where(fn ($query) => ctype_digit($value) ? $query->whereKey((int) $value)->orWhere('number', $value) : $query->where('number', $value))
            ->first();

        if ($invoice === null || !$user->can('view', $invoice)) {
            throw ValidationException::withMessages(['invoice' => "{$profile->short_name} has no invoice «{$value}»."]);
        }

        return ['invoice' => (new InvoiceResource($invoice))->toArray($this->apiRequest($user, ['include' => 'client,contract,items,payments']))];
    }
}
