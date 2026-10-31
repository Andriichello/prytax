<?php

namespace PryTax\Mcp\Tools;

use Carbon\CarbonImmutable;
use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Enums\TaxType;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;
use PryTax\Services\Taxes\TaxLedger;

/**
 * Class GetTaxLedger.
 *
 * A ФОП's taxes of a year (Податки): what each tax accrued, what was sent and paid, by month, with the summary.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get a ФОП\'s tax ledger (Податки) for a year: ЄП (single_tax), ВЗ (military_levy) and ЄСВ (social_contribution) accrued, sent and paid (UAH), by month, with the payments\' statuses (unpaid, failed, sent, paid) and a summary. Filters: quarters, tax types, statuses. This year by default.')]
class GetTaxLedger extends ReadTool
{
    /**
     * The model, whose policy authorizes the read.
     *
     * @var class-string
     */
    protected string $model = TaxPayment::class;

    /**
     * Get the validation rules of the tool's own arguments.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'quarters' => ['nullable', 'array'],
            'quarters.*' => ['integer', 'min:1', 'max:4'],
            'tax_types' => ['nullable', 'array'],
            'tax_types.*' => [Rule::enum(TaxType::class)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::in(TaxLedger::STATUSES)],
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
            'quarters' => $schema->array()->items($schema->integer()->min(1)->max(4))->description('Only these quarters; the whole year by default.'),
            'tax_types' => $schema->array()->items($schema->string()->enum(TaxType::values()))->description('Only these taxes; all by default.'),
            'statuses' => $schema->array()->items($schema->string()->enum(TaxLedger::STATUSES))->description('Only these statuses; all by default.'),
        ];
    }

    /**
     * Read the ledger.
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
        return app(TaxLedger::class)->forPeriod(
            $profile,
            (int) ($arguments['year'] ?? CarbonImmutable::now()->year),
            array_map('intval', $arguments['quarters'] ?? []),
            array_map(fn (string $type) => TaxType::from($type), $arguments['tax_types'] ?? []),
            $arguments['statuses'] ?? [],
        );
    }
}
