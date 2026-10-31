<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Services\Reports\PracticeOverview;

/**
 * Class GetPracticeOverview.
 *
 * The quarter of every ФОП, which Claude may see, side by side: the income against the cap, what was invoiced and
 * received per currency, and each tax owed, paid and outstanding.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get a quarter of every ФОП Claude may see, side by side: income (UAH) against the income cap, invoiced and received per currency, and each tax (ЄП, ВЗ, ЄСВ) owed, paid, pending and outstanding. This quarter by default.')]
class GetPracticeOverview extends ReadTool
{
    /**
     * Whether the tool reads one ФОП.
     *
     * @var bool
     */
    protected bool $ofProfile = false;

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
            'with_inactive' => ['nullable', 'boolean'],
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
            'quarter' => $schema->integer()->min(1)->max(4)->description('The quarter, 1 to 4; this quarter by default.'),
            'with_inactive' => $schema->boolean()->description('Also the ФОП closed before the quarter.'),
        ];
    }

    /**
     * Read the overview.
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
        $overview = app(PracticeOverview::class)->forQuarter($user, $year, $quarter, (bool) ($arguments['with_inactive'] ?? false));
        $overview['profiles'] = array_values(array_filter(
            $overview['profiles'],
            fn (array $card) => $integration->allowsProfile((int) $card['profile']['id']),
        ));

        return $overview;
    }
}
