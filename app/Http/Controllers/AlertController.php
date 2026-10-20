<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;
use PryTax\Enums\AlertCategory;
use PryTax\Http\Requests\Alerts\ReadAlertRequest;
use PryTax\Http\Resources\AlertCollection;
use PryTax\Http\Resources\AlertResource;
use PryTax\Models\Alert;
use PryTax\Policies\AlertPolicy;
use PryTax\Repositories\AlertRepository;

/**
 * Class AlertController.
 *
 * Notifications of a profile ("Сповіщення"): `profiles/{profile}/alerts`, newest first, each with when the signed-in
 * user read it, filtered by `category` and `unread`. Listing never marks anything read; `POST alerts/{id}/read`
 * does, when the user taps the notification, its action or its ✓ (design.md §9). The counts are those of the chips.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AlertController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = AlertResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = AlertCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'read' => ReadAlertRequest::class,
        'counts' => IndexRequest::class,
    ];

    /**
     * AlertController constructor.
     *
     * @param AlertPolicy $policy
     * @param AlertRepository $repository
     */
    public function __construct(AlertPolicy $policy, AlertRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `read` operation: mark the notification as read by the user, once.
     *
     * @param ReadAlertRequest $request
     *
     * @return ApiResponse
     */
    protected function handleRead(ReadAlertRequest $request): ApiResponse
    {
        /** @var Alert $alert */
        $alert = Alert::query()->findOrFail($request->getId());

        DB::table('alert_reads')->insertOrIgnore([
            'alert_id' => $alert->getKey(),
            'user_id' => $request->user()->getKey(),
            'read_at' => now(),
        ]);

        $alert = Alert::query()->withReadAtOf($request->user())->findOrFail($alert->getKey());

        return $this->asResourceResponse($alert)->setMessage('Read');
    }

    /**
     * Handle the `counts` operation: the unresolved notifications, all, unread and per category.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleCounts(IndexRequest $request): ApiResponse
    {
        $user = $request->user();

        $all = Alert::query()->unresolved()->groupBy('category')->selectRaw('category, COUNT(*) as total')->pluck('total', 'category');
        $unread = Alert::query()->unresolved()->unreadBy($user)->groupBy('category')->selectRaw('category, COUNT(*) as total')->pluck('total', 'category');

        $categories = [];

        foreach (AlertCategory::cases() as $category) {
            $categories[$category->value] = [
                'all' => (int) ($all[$category->value] ?? 0),
                'unread' => (int) ($unread[$category->value] ?? 0),
            ];
        }

        return ApiResponse::ok(['data' => [
            'all' => (int) $all->sum(),
            'unread' => (int) $unread->sum(),
            'categories' => $categories,
        ]]);
    }
}
