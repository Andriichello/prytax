<?php

namespace PryTax\Mcp\Tools;

use Core\Helpers\CurrentOrganization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Mcp\ApiDispatcher;
use PryTax\Mcp\McpSession;
use PryTax\Services\Integrations\ClaudeIntegration;

/**
 * Class ApiRead.
 *
 * Reading anything the acting user may read: a GET of PryTax's API (`api-endpoints` lists them), in a transaction,
 * which is rolled back, as every read of Claude's is.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Read anything Claude\'s user may read in PryTax: a GET of its API, such as profiles/1/invoices?filter[status][in]=sent&include=client or profiles/1/accounting/2026/3. api-endpoints lists the paths. The answer is the API\'s JSON: {data, meta}.')]
class ApiRead extends SessionTool
{
    /**
     * Handle the call.
     *
     * @param Request $request
     * @param McpSession $session
     * @param ApiDispatcher $api
     * @param ClaudeIntegration $claude
     *
     * @return Response|ResponseFactory
     */
    public function handle(Request $request, McpSession $session, ApiDispatcher $api, ClaudeIntegration $claude): Response|ResponseFactory
    {
        [$integration, $user] = $session->open();
        $arguments = $request->validate(['path' => ['required', 'string', 'max:500'], 'query' => ['nullable', 'array']]);
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $answer = CurrentOrganization::run((int) $user->organization_id, fn () => $api->dispatch(
                $integration,
                $user,
                'GET',
                (string) $arguments['path'],
                (array) ($arguments['query'] ?? []),
            ));
        } finally {
            $connection->rollBack();
        }

        $claude->recordUse($integration, 'api-read ' . $arguments['path']);

        return Response::structured($answer);
    }

    /**
     * Get the tool's input schema.
     *
     * @param JsonSchema $schema
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required()->description('The path under /api/, such as profiles/1/invoices; it may carry its query too.'),
            'query' => $schema->object()->description('The query, such as {"filter": {"status": {"in": "sent,paid"}}, "include": "client", "page": {"size": 50}}.'),
        ];
    }
}
