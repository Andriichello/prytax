<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Enums\AlertCategory;
use PryTax\Helpers\CurrentProfile;
use PryTax\Http\Resources\AlertResource;
use PryTax\Models\Alert;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Repositories\AlertRepository;

/**
 * Class ListAlerts.
 *
 * What needs attention: the unresolved notifications of one ФОП, or of every ФОП Claude may see, with whether the
 * acting user read them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('List what needs attention: the unresolved notifications (deadlines, invoices, funds) of one ФОП, or of every ФОП Claude may see when none is named, with whether they were read. Filters: unread only, categories.')]
class ListAlerts extends ReadTool
{
    /**
     * The model, whose policy authorizes the read.
     *
     * @var class-string
     */
    protected string $model = Alert::class;

    /**
     * Whether the tool reads one ФОП: one, or every one, so it names its own.
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
            'profile' => ['nullable', 'regex:/^[^\n]{1,255}$/u'],
            'unread' => ['nullable', 'boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::enum(AlertCategory::class)],
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
            'profile' => $schema->string()->description('The ФОП: its id, or part of its name; every ФОП Claude may see when left out.'),
            'unread' => $schema->boolean()->description('Only the unread ones.'),
            'categories' => $schema->array()->items($schema->string()->enum(AlertCategory::values()))->description('Only these categories.'),
        ];
    }

    /**
     * Read the notifications.
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
        $profiles = isset($arguments['profile'])
            ? collect([$this->profileOf((string) $arguments['profile'], $user, $integration)])
            : $this->allowedProfiles($user, $integration);
        $request = $this->apiRequest($user, ['filter' => array_filter([
            'unread' => empty($arguments['unread']) ? null : '1',
            'category' => empty($arguments['categories']) ? null : ['in' => implode(',', $arguments['categories'])],
        ])]);

        return [
            'profiles' => $profiles->map(fn (Profile $profile) => [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'alerts' => CurrentProfile::run($profile, fn () => app(AlertRepository::class)->builder($request)->get()
                    ->map(fn (Alert $alert) => (new AlertResource($alert))->toArray($request))
                    ->all()),
            ])->all(),
        ];
    }
}
