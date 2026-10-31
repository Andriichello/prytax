<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;
use PryTax\Services\Reports\DeclarationFigures;

/**
 * Class GetDeclaration.
 *
 * The figures of a ФОП's declaration of a quarter: its income, its taxes, its ЄСВ and the income cap.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get the figures of a ФОП\'s tax declaration for a quarter: income (UAH, the quarter and year to date), ЄП and ВЗ (single_tax.to_pay and military_levy.to_pay: the quarter\'s declared amounts, the year to date less what was declared before, before payments), the quarter\'s ЄСВ, the income cap, and what remains of each tax (remaining.<tax>: owed, paid, pending, the running balance with the opening balance, negative when owed, and to_pay, what is left to pay net of payments and overpayments: the figure to quote as owed). The previous quarter is the one usually declared; this quarter by default.')]
class GetDeclaration extends ReadTool
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
     * Read the declaration's figures.
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

        return app(DeclarationFigures::class)->forQuarter($profile, $year, $quarter);
    }
}
