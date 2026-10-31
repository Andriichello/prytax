<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Http\Resources\InvoiceResource;
use PryTax\Models\Integration;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Repositories\InvoiceRepository;

/**
 * Class ListInvoices.
 *
 * A ФОП's invoices, the newest first, as Інвойси filters them: by status, period and client, with what was paid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('List a ФОП\'s invoices, the newest first, with their client, dates, currency, total and paid amount. Filters: status (draft, ready, sent, paid, cancelled; virtual for payments without an invoice), period (2026, 2026-Q4 or 2026-10), client id.')]
class ListInvoices extends ReadTool
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
        return [
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in([...InvoiceStatus::values(), InvoiceSource::Virtual->value])],
            'period' => ['nullable', 'array'],
            'period.*' => ['regex:/^\d{4}(-Q[1-4]|-(0[1-9]|1[0-2]))?$/'],
            'client' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
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
        return [
            'status' => $schema->array()->items($schema->string()->enum([...InvoiceStatus::values(), InvoiceSource::Virtual->value]))->description('Only these statuses.'),
            'period' => $schema->array()->items($schema->string())->description('Only invoices issued in these periods: a year (2026), a quarter (2026-Q4) or a month (2026-10).'),
            'client' => $schema->integer()->description('Only this client\'s (its id, from list-clients).'),
            'limit' => $schema->integer()->min(1)->max(100)->description('How many, 25 by default.'),
        ];
    }

    /**
     * Read the invoices.
     *
     * @param array<string, mixed> $arguments
     * @param User $user
     * @param Integration $integration
     * @param Profile|null $profile
     *
     * @return array<string, mixed>
     */
    protected function read(array $arguments, User $user, Integration $integration, ?Profile $profile): array
    {
        $request = $this->apiRequest($user, [
            'include' => 'client',
            'filter' => array_filter([
                'status' => empty($arguments['status']) ? null : ['in' => implode(',', $arguments['status'])],
                'period' => empty($arguments['period']) ? null : ['in' => implode(',', $arguments['period'])],
                'client_id' => empty($arguments['client']) ? null : ['eq' => (string) $arguments['client']],
            ]),
        ]);
        $query = app(InvoiceRepository::class)->builder($request)->with('client');

        return [
            'found' => (clone $query)->count(),
            'invoices' => $query->limit((int) ($arguments['limit'] ?? 25))->get()
                ->map(fn (Invoice $invoice) => (new InvoiceResource($invoice))->toArray($request))
                ->all(),
        ];
    }
}
