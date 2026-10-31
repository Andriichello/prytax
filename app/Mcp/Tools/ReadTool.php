<?php

namespace PryTax\Mcp\Tools;

use Carbon\CarbonImmutable;
use Closure;
use Core\Helpers\CurrentOrganization;
use Core\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use PryTax\Helpers\CurrentProfile;
use PryTax\Mcp\McpSession;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Services\Integrations\ClaudeIntegration;

/**
 * Class ReadTool.
 *
 * A tool of PryTax's MCP server, which only reads (builds.md §7). Each call: the integration, on, and its acting user
 * (`McpSession`); the arguments validated (the schema isn't enforced by the protocol); the read authorized as the API
 * authorizes it (the user's policy ability on the model); the ФОП named (by id or part of its name, among those the
 * integration allows, or the only one); then the read, in the organization and the ФОП, **in a transaction, which is
 * always rolled back**: reads, which write as they go (the tax periods and ЄСВ months they ensure, their audit), keep
 * nothing. Afterwards the call is recorded on the integration, and the result returned as structured content.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
abstract class ReadTool extends Tool
{
    /**
     * The model, whose policy authorizes the read, as the API's.
     *
     * @var class-string
     */
    protected string $model = Profile::class;

    /**
     * The ability, which the read needs on the model.
     *
     * @var string
     */
    protected string $ability = 'viewAny';

    /**
     * Whether the tool reads one ФОП, named by `profile`.
     *
     * @var bool
     */
    protected bool $ofProfile = true;

    /**
     * Read, in the organization (and the ФОП), with the arguments validated, and get the result.
     *
     * @param array<string, mixed> $arguments
     * @param User $user
     * @param Integration $integration
     * @param Profile|null $profile
     *
     * @return array<string, mixed>
     */
    abstract protected function read(array $arguments, User $user, Integration $integration, ?Profile $profile): array;

    /**
     * Get the validation rules of the tool's own arguments.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * Get the schema of the tool's own arguments.
     *
     * @param JsonSchema $schema
     *
     * @return array<string, Type>
     */
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Handle the call.
     *
     * @param Request $request
     * @param McpSession $session
     * @param ClaudeIntegration $claude
     *
     * @return Response|ResponseFactory
     */
    public function handle(Request $request, McpSession $session, ClaudeIntegration $claude): Response|ResponseFactory
    {
        [$integration, $user] = $session->open();
        $arguments = $request->validate([
            // A string or an id, which Claude may send as a number
            ...($this->ofProfile ? ['profile' => ['nullable', 'regex:/^[^\n]{1,255}$/u']] : []),
            ...$this->rules(),
        ]);

        if (!$user->can($this->ability, $this->model)) {
            return Response::error("Claude reads PryTax as {$user->name}, whose role doesn't allow this. The organization's owner may choose another user in Settings → Integrations → Claude.");
        }

        $data = CurrentOrganization::run((int) $user->organization_id, function () use ($arguments, $user, $integration) {
            $profile = $this->ofProfile ? $this->profileOf(isset($arguments['profile']) ? (string) $arguments['profile'] : null, $user, $integration) : null;

            return $this->rolledBack(fn () => $profile === null
                ? $this->read($arguments, $user, $integration, null)
                : CurrentProfile::run($profile, fn () => $this->read($arguments, $user, $integration, $profile)));
        });

        $claude->recordUse($integration, $this->name());

        return Response::structured($data);
    }

    /**
     * Get the tool's input schema: the ФОП, if it reads one, and its own arguments.
     *
     * @param JsonSchema $schema
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...($this->ofProfile ? ['profile' => $schema->string()->description('The ФОП: its id, or part of its name; may be left out when there is one.')] : []),
            ...$this->arguments($schema),
        ];
    }

    /**
     * Get the tool's annotations: it only reads, the same each time, and nothing outside PryTax.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return [...parent::annotations(), 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    /**
     * Get the ФОП, which the argument names, among those of the organization, which the integration allows: by id, by
     * its full or short name, or by part of it; or the only one, or the only active one, when none is named.
     *
     * @param string|null $name
     * @param User $user
     * @param Integration $integration
     *
     * @return Profile
     * @throws ValidationException
     */
    protected function profileOf(?string $name, User $user, Integration $integration): Profile
    {
        $profiles = $this->allowedProfiles($user, $integration);
        $name = trim((string) $name);

        if ($name === '') {
            $active = $profiles->where('is_active', true);
            $only = $profiles->count() === 1 ? $profiles->first() : ($active->count() === 1 ? $active->first() : null);

            return $only ?? throw ValidationException::withMessages(['profile' => 'Name the ФОП: ' . $this->listOf($profiles) . '.']);
        }

        if (ctype_digit($name)) {
            return $profiles->firstWhere('id', (int) $name)
                ?? throw ValidationException::withMessages(['profile' => "There's no ФОП #$name here: " . $this->listOf($profiles) . '.']);
        }

        $needle = mb_strtolower($name);
        $exact = $profiles->filter(fn (Profile $profile) => in_array($needle, [mb_strtolower($profile->full_name), mb_strtolower($profile->short_name)], true));
        $matches = $exact->isNotEmpty() ? $exact : $profiles->filter(fn (Profile $profile) => str_contains(mb_strtolower($profile->full_name), $needle)
            || str_contains(mb_strtolower($profile->short_name), $needle));

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => throw ValidationException::withMessages(['profile' => "No ФОП here is named «{$name}»: " . $this->listOf($profiles) . '.']),
            default => throw ValidationException::withMessages(['profile' => "Several ФОП match «{$name}»: " . $this->listOf($matches) . '.']),
        };
    }

    /**
     * Get the ФОП of the organization, which the integration allows, the active first.
     *
     * @param User $user
     * @param Integration $integration
     *
     * @return Collection<int, Profile>
     */
    protected function allowedProfiles(User $user, Integration $integration): Collection
    {
        return Profile::query()
            ->index($user)
            ->orderByDesc('is_active')
            ->orderBy('full_name')
            ->get()
            ->filter(fn (Profile $profile) => $integration->allowsProfile($profile->id))
            ->values();
    }

    /**
     * Run the read in a transaction, which is always rolled back, and get its result as plain data: its resources
     * resolved there, in the organization and the ФОП, not when the answer is encoded, after them.
     *
     * @param Closure(): array<string, mixed> $read
     *
     * @return array<string, mixed>
     */
    protected function rolledBack(Closure $read): array
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            return (array) json_decode((string) json_encode($read(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Get a request, as the API's would be, of the user, with the query's filters, sorts, includes and appends, which
     * the repositories and resources read.
     *
     * @param User $user
     * @param array<string, mixed> $query
     *
     * @return HttpRequest
     */
    protected function apiRequest(User $user, array $query = []): HttpRequest
    {
        $request = HttpRequest::create('/', 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * Get the year and the quarter, which the arguments name, or today's (Kyiv).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: int}
     */
    protected function periodOf(array $arguments): array
    {
        $today = CarbonImmutable::now();

        return [(int) ($arguments['year'] ?? $today->year), (int) ($arguments['quarter'] ?? $today->quarter)];
    }

    /**
     * Get the ФОП as a list for a message, such as `#1 Коваленко Олена Петрівна, #3 Бондар Ірина Василівна`.
     *
     * @param Collection<int, Profile> $profiles
     *
     * @return string
     */
    protected function listOf(Collection $profiles): string
    {
        return $profiles->isEmpty() ? 'none is open to Claude' : $profiles->map(fn (Profile $profile) => "#$profile->id $profile->full_name")->implode(', ');
    }
}
