<?php

namespace PryTax\Mcp\Tools;

use Core\Helpers\CurrentOrganization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Mcp\ApiDispatcher;
use PryTax\Mcp\McpSession;
use PryTax\Services\Integrations\ClaudeIntegration;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Class ApiWrite.
 *
 * Changing anything the acting user may change, once an owner allowed Claude to change data: a POST, PUT, PATCH or
 * DELETE of PryTax's API, as the SPA sends them, with local files as uploads (an invoice's PDF) and the headers, which
 * some changes take: a payment's `Idempotency-Key`, an invoice's `If-Unmodified-Since` (its `updated_at`, as Claude
 * read it). Listed only while changes are allowed; refused otherwise.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Change data in PryTax as Claude\'s user may: a POST, PUT, PATCH or DELETE of its API (api-endpoints lists them with their fields and rules), such as POST profiles/1/clients, POST profiles/1/invoices, POST profiles/1/payments. Local files go as uploads: {"files": {"file": "/home/me/invoice.pdf"}}. An invoice\'s PATCH takes if_unmodified_since: its updated_at, as read. The answer is the API\'s JSON; a 422 lists what to fix. Ask the user before deleting anything.')]
class ApiWrite extends SessionTool
{
    /**
     * Whether the tool only reads.
     *
     * @var bool
     */
    protected bool $readOnly = false;

    /**
     * Determine whether the tool is offered: while the integration may change data.
     *
     * @param McpSession $session
     *
     * @return bool
     */
    public function shouldRegister(McpSession $session): bool
    {
        return (bool) $session->integration()?->allowsWrites();
    }

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

        if (!$integration->allowsWrites()) {
            return Response::error('Claude may only read: its owner allows changes in PryTax, Settings → Integrations → Claude.');
        }

        $arguments = $request->validate([
            'method' => ['required', 'in:POST,PUT,PATCH,DELETE,post,put,patch,delete'],
            'path' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'array'],
            'query' => ['nullable', 'array'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'if_unmodified_since' => ['nullable', 'string', 'max:64'],
        ]);
        $headers = array_filter([
            'Idempotency-Key' => $arguments['idempotency_key'] ?? null,
            'If-Unmodified-Since' => $arguments['if_unmodified_since'] ?? null,
        ], fn (?string $value) => $value !== null);
        $answer = CurrentOrganization::run((int) $user->organization_id, fn () => $api->dispatch(
            $integration,
            $user,
            (string) $arguments['method'],
            (string) $arguments['path'],
            (array) ($arguments['query'] ?? []),
            $arguments['body'] ?? null,
            (array) ($arguments['files'] ?? []),
            $headers,
        ));

        // The API's message is the SPA's ("reload the page"), which doesn't tell Claude what to send
        if ($answer['status'] === HttpResponse::HTTP_PRECONDITION_REQUIRED) {
            $answer['hint'] = 'Send if_unmodified_since: the updated_at of what you change, as api-read gave it.';
        }

        $claude->recordUse($integration, 'api-write ' . strtoupper((string) $arguments['method']) . ' ' . $arguments['path']);

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
            'method' => $schema->string()->enum(['POST', 'PUT', 'PATCH', 'DELETE'])->required(),
            'path' => $schema->string()->required()->description('The path under /api/, such as profiles/1/payments.'),
            'body' => $schema->object()->description('The fields, as api-endpoints lists them; amounts as strings such as "1200.50", dates as YYYY-MM-DD.'),
            'query' => $schema->object()->description('A query, if the endpoint takes one.'),
            'files' => $schema->object()->description('Local files to upload, by field: {"file": "/path/to/invoice.pdf"}.'),
            'idempotency_key' => $schema->string()->description('An Idempotency-Key, which the payments\' and transfers\' POSTs take, so a retry records nothing twice.'),
            'if_unmodified_since' => $schema->string()->description('The updated_at of the invoice, as api-read gave it, which an invoice\'s PATCH requires: a change made since is refused (409), not overwritten.'),
        ];
    }
}
