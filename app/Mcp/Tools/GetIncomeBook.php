<?php

namespace PryTax\Mcp\Tools;

use Carbon\CarbonImmutable;
use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Models\Integration;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Reports\AccountingBooks;

/**
 * Class GetIncomeBook.
 *
 * A ФОП's income book (Книга обліку доходів) of a year: its receipts by quarter and month, the year to date and the
 * cap.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get a ФОП\'s income book (Книга обліку доходів) for a year: the receipts by quarter and month (UAH), the year-to-date income, and the income cap with how much of it is left. This year by default.')]
class GetIncomeBook extends ReadTool
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
        return ['year' => ['nullable', 'integer', 'min:2020', 'max:2100']];
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
        return ['year' => $schema->integer()->description('The year, such as 2026; this year by default.')];
    }

    /**
     * Read the book.
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
        return app(AccountingBooks::class)->income($profile, (int) ($arguments['year'] ?? CarbonImmutable::now()->year));
    }
}
