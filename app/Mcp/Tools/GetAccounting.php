<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Models\Integration;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Reports\AccountingView;

/**
 * Class GetAccounting.
 *
 * A ФОП's quarter as Облік shows it: its receipts, its invoices, and the summary of income and taxes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get a ФОП\'s quarter as PryTax\'s accounting (Облік) shows it: the receipts, the invoices, and the summary: the quarter\'s and the year-to-date income (UAH), ЄП, ВЗ and ЄСВ, and the income cap. This quarter by default.')]
class GetAccounting extends ReadTool
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
        return ['year' => ['nullable', 'integer', 'min:2020', 'max:2100'], 'quarter' => ['nullable', 'integer', 'min:1', 'max:4']];
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
            'quarter' => $schema->integer()->min(1)->max(4)->description('The quarter, 1 to 4; this quarter by default.'),
        ];
    }

    /**
     * Read the quarter.
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
        [$year, $quarter] = $this->periodOf($arguments);

        return app(AccountingView::class)->forQuarter($profile, $year, $quarter);
    }
}
