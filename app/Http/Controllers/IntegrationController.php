<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PryTax\Http\Requests\Integrations\ConfigureIntegrationRequest;
use PryTax\Http\Requests\Integrations\IntegrationRequest;
use PryTax\Http\Resources\IntegrationCollection;
use PryTax\Http\Resources\IntegrationResource;
use PryTax\Models\Integration;
use PryTax\Policies\IntegrationPolicy;
use PryTax\Repositories\IntegrationRepository;
use PryTax\Services\Integrations\ClaudeConnection;
use PryTax\Services\Integrations\ClaudeIntegration;
use PryTax\Services\Integrations\ClaudeProbe;

/**
 * Class IntegrationController.
 *
 * The organization's Claude integration (builds.md §7), on Налаштування → Інтеграції, for its owners: its state (off
 * until one turns it on), turning it on and off and configuring it (`PUT`), a new key, the connection to paste into
 * Claude Code or Claude Desktop (only while it's on), and the probe of this computer's Claude configs.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class IntegrationController extends CrudController
{
    /**
     * Resource class for one integration.
     *
     * @var string
     */
    protected string $resourceClass = IntegrationResource::class;

    /**
     * Collection class for a list of integrations.
     *
     * @var string
     */
    protected string $collectionClass = IntegrationCollection::class;

    /**
     * Operations of the controller, by name, with their requests.
     *
     * @var array<string, class-string>
     */
    protected array $operations = [
        'claude' => IndexRequest::class,
        'configure' => ConfigureIntegrationRequest::class,
        'rekey' => IntegrationRequest::class,
        'connection' => IntegrationRequest::class,
        'probe' => IntegrationRequest::class,
    ];

    /**
     * IntegrationController constructor.
     *
     * @param IntegrationPolicy $policy
     * @param IntegrationRepository $repository
     * @param ClaudeIntegration $claude
     */
    public function __construct(IntegrationPolicy $policy, IntegrationRepository $repository, protected ClaudeIntegration $claude)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Register the controller's routes, in the group of the organization's.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::get('integrations/claude', [static::class, 'claude'])->name('integrations.claude.show');
        Route::put('integrations/claude', [static::class, 'configure'])->name('integrations.claude.update');
        Route::post('integrations/claude/key', [static::class, 'rekey'])->name('integrations.claude.key');
        Route::get('integrations/claude/connection', [static::class, 'connection'])->name('integrations.claude.connection');
        Route::post('integrations/claude/probe', [static::class, 'probe'])->name('integrations.claude.probe');
    }

    /**
     * Handle the `claude` operation: the organization's Claude integration, off when it has none.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleClaude(IndexRequest $request): ApiResponse
    {
        return $this->asResourceResponse($this->integrationOf($request->orgId()));
    }

    /**
     * Handle the `configure` operation: turn it on or off (who and when, a key the first time), and choose its acting
     * user and ФОП.
     *
     * @param ConfigureIntegrationRequest $request
     *
     * @return ApiResponse
     */
    protected function handleConfigure(ConfigureIntegrationRequest $request): ApiResponse
    {
        $integration = DB::transaction(function () use ($request) {
            $integration = $this->integrationOf($request->orgId());

            if ($request->has('enabled')) {
                $request->boolean('enabled')
                    ? $this->claude->enable($integration, $request->user())
                    : $this->claude->disable($integration, $request->user());
            }

            $this->claude->configure($integration, $request->safe()->only(['acting_user_id', 'profile_ids']));
            $integration->save();

            return $integration;
        });

        return $this->asResourceResponse($integration->fresh(['enabledBy', 'disabledBy', 'actingUser']))->setMessage('Updated');
    }

    /**
     * Handle the `rekey` operation: a new key, which stops the old one, while it's on.
     *
     * @param IntegrationRequest $request
     *
     * @return ApiResponse
     */
    protected function handleRekey(IntegrationRequest $request): ApiResponse
    {
        $integration = $this->integrationOf($request->orgId());

        if (!$integration->isEnabled()) {
            return $this->turnedOff();
        }

        $this->claude->rekey($integration);
        $integration->save();

        return $this->asResourceResponse($integration->fresh(['enabledBy', 'disabledBy', 'actingUser']))->setMessage('Updated');
    }

    /**
     * Handle the `connection` operation: how Claude starts the server for it, with its key, while it's on.
     *
     * @param IntegrationRequest $request
     *
     * @return ApiResponse
     */
    protected function handleConnection(IntegrationRequest $request): ApiResponse
    {
        $integration = $this->integrationOf($request->orgId());

        return $integration->isEnabled()
            ? ApiResponse::ok(['data' => app(ClaudeConnection::class)->for($integration)])
            : $this->turnedOff();
    }

    /**
     * Handle the `probe` operation: whether Claude Code and Claude Desktop on this computer have its server.
     *
     * @param IntegrationRequest $request
     *
     * @return ApiResponse
     */
    protected function handleProbe(IntegrationRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => app(ClaudeProbe::class)->probe($this->integrationOf($request->orgId()))]);
    }

    /**
     * Get the organization's Claude integration, with its users.
     *
     * @param int $organizationId
     *
     * @return Integration
     */
    protected function integrationOf(int $organizationId): Integration
    {
        return Integration::of($organizationId, Integration::CLAUDE)->loadMissing(['enabledBy', 'disabledBy', 'actingUser']);
    }

    /**
     * Get the answer to a request, which needs the integration on.
     *
     * @return ApiResponse
     */
    protected function turnedOff(): ApiResponse
    {
        return ApiResponse::make([], 409, __('Turn Claude on in Settings → Integrations first.'));
    }
}
