<?php

namespace PryTax\Mcp\Tools;

use Carbon\CarbonImmutable;
use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Http\Resources\PaymentResource;
use PryTax\Models\Integration;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Repositories\PaymentRepository;

/**
 * Class ListReceipts.
 *
 * A ФОП's receipts (its income), the newest first, of a year, a quarter or a month, with the invoices they paid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('List a ФОП\'s receipts (income), the newest first, for a year, a quarter or a month: the amount in its currency, the NBU rate, the UAH amount, which counts as income, and the invoice it paid. This year by default.')]
class ListReceipts extends ReadTool
{
    /**
     * The model, whose policy authorizes the read.
     *
     * @var class-string
     */
    protected string $model = Payment::class;

    /**
     * Get the validation rules of the tool's own arguments.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'quarter' => ['nullable', 'integer', 'min:1', 'max:4'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
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
            'year' => $schema->integer()->description('The year, such as 2026; this year by default.'),
            'quarter' => $schema->integer()->min(1)->max(4)->description('Only this quarter of the year.'),
            'month' => $schema->integer()->min(1)->max(12)->description('Only this month of the year.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('How many, 50 by default.'),
        ];
    }

    /**
     * Read the receipts.
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
        if (!empty($arguments['quarter']) && !empty($arguments['month']) && (int) ceil($arguments['month'] / 3) !== (int) $arguments['quarter']) {
            throw ValidationException::withMessages(['month' => "Month {$arguments['month']} isn't in quarter {$arguments['quarter']}: give one of them, or a month of that quarter."]);
        }

        $request = $this->apiRequest($user, [
            'include' => 'invoice',
            'filter' => array_filter([
                'period_year' => ['eq' => (string) ($arguments['year'] ?? CarbonImmutable::now()->year)],
                'period_quarter' => empty($arguments['quarter']) ? null : ['eq' => (string) $arguments['quarter']],
                'period_month' => empty($arguments['month']) ? null : ['eq' => (string) $arguments['month']],
            ]),
        ]);
        $query = app(PaymentRepository::class)->builder($request)->with(['invoice' => fn ($invoices) => $invoices->withPaidAmount()]);

        return [
            'found' => (clone $query)->count(),
            'receipts' => $query->limit((int) ($arguments['limit'] ?? 50))->get()
                ->map(fn (Payment $payment) => (new PaymentResource($payment))->toArray($request))
                ->all(),
        ];
    }
}
