<?php

namespace PryTax\Mcp\Tools;

use Laravel\Mcp\Server\Tool;

/**
 * Class SessionTool.
 *
 * A tool of PryTax's MCP server, which isn't a read of one model (builds.md §7): the API's endpoints, reading or
 * changing through the API, a spreadsheet. Each call opens the session (`McpSession`: the integration on, its acting
 * user signed in) and is recorded on the integration afterwards.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
abstract class SessionTool extends Tool
{
    /**
     * Whether the tool only reads, which its annotations tell Claude.
     *
     * @var bool
     */
    protected bool $readOnly = true;

    /**
     * Get the tool's annotations: whether it only reads, and nothing outside PryTax and this computer.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return [
            ...parent::annotations(),
            'readOnlyHint' => $this->readOnly,
            'destructiveHint' => !$this->readOnly,
            'idempotentHint' => $this->readOnly,
            'openWorldHint' => false,
        ];
    }
}
