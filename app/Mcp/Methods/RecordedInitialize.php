<?php

namespace PryTax\Mcp\Methods;

use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PryTax\Mcp\McpSession;

/**
 * Class RecordedInitialize.
 *
 * The MCP handshake, which also records on the integration its key names that Claude connected, and with which client
 * (Claude Code, Claude Desktop) and version (builds.md §7): Налаштування → Інтеграції shows it. A failure to record
 * doesn't fail the handshake.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Methods
 */
class RecordedInitialize extends Initialize
{
    /**
     * Handle the handshake, and record the client.
     *
     * @param JsonRpcRequest $request
     * @param ServerContext $context
     *
     * @return JsonRpcResponse
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $response = parent::handle($request, $context);
        rescue(fn () => app(McpSession::class)->recordConnection((array) ($request->params['clientInfo'] ?? [])));

        return $response;
    }
}
