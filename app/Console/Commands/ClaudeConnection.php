<?php

namespace PryTax\Console\Commands;

use Core\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Mcp\DatabaseKey;
use PryTax\Models\Integration;
use PryTax\Services\Integrations\ClaudeConnection as Connection;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ClaudeConnection.
 *
 * The connection of each Claude integration, which is on, in this installation's database (builds.md §7): how Claude
 * Code or Claude Desktop starts PryTax's server, with its key, for the starter's Tools → Claude (`--json`) or a person.
 * The desktop app's data (`--desktop`) is read with its own key, and its connection carries the desktop app's
 * settings. Without any integration on, it says where to turn Claude on.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:claude-connection {--desktop : The desktop app\'s data} {--json : The connections as JSON, on one line}')]
#[Description('Show how Claude connects to PryTax: each Claude integration\'s, which is on, command and config')]
class ClaudeConnection extends Command
{
    /**
     * Execute the console command.
     *
     * @param Connection $connection
     *
     * @return int
     */
    public function handle(Connection $connection): int
    {
        DatabaseKey::apply();

        $connections = Integration::query()
            ->withoutGlobalScope(Integration::ORGANIZATION_SCOPE)
            ->where('kind', Integration::CLAUDE)
            ->whereNotNull('enabled_at')
            ->orderBy('organization_id')
            ->get()
            ->map(fn (Integration $integration) => [
                'organization' => (string) Organization::query()->whereKey($integration->organization_id)->value('name'),
                ...$connection->for($integration, $this->option('desktop') ? 'desktop' : 'local'),
            ])
            ->all();

        if ($connections === []) {
            $this->components->error('Claude isn\'t on here: an owner turns it on in Settings → Integrations (Налаштування → Інтеграції).');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode($connections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        foreach ($connections as $item) {
            $this->components->twoColumnDetail("<options=bold>{$item['organization']}</>", $item['name']);
            $this->output->writeln($item['claude_code'], OutputInterface::OUTPUT_RAW);
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
