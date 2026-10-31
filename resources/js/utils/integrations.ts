import type { ClaudeIntegration, ClaudeProbeStatus } from '@/api/types';
import { t } from '@/i18n';
import { kyivFullDate, kyivTime, relativeDay } from '@/utils/backups';

/** Claude's client, by the name it introduced itself with: Claude Code, Claude Desktop, or its own. */
export function clientName(client: ClaudeIntegration['last_client']): string {
    switch (client?.name) {
        case 'claude-code':
            return 'Claude Code';
        case 'claude-ai':
            return 'Claude Desktop';
        default:
            return client?.name || 'Claude';
    }
}

/** «вчора 22:41»: when something happened, near today. */
export function nearMoment(timestamp: string | null | undefined): string {
    return `${relativeDay(timestamp).toLocaleLowerCase()} ${kyivTime(timestamp)}`;
}

/** Who turned the integration on or off, and when, or null before anyone did. */
export function switchedLine(integration: ClaudeIntegration): string | null {
    const [at, by] = integration.enabled ? [integration.enabled_at, integration.enabled_by] : [integration.disabled_at, integration.disabled_by];

    if (!at) {
        return null;
    }

    const values = { date: kyivFullDate(at), time: kyivTime(at), name: by?.name ?? '—' };

    return integration.enabled ? t('settings.integrations.enabledLine', values) : t('settings.integrations.disabledLine', values);
}

/** What Claude last did: its connection (client, version, when), or that it never connected. */
export function connectedLine(integration: ClaudeIntegration): string {
    return integration.last_connected_at
        ? t('settings.integrations.connected', {
            client: clientName(integration.last_client),
            version: integration.last_client?.version ?? '',
            when: nearMoment(integration.last_connected_at),
        }).replace('  ', ' ')
        : t('settings.integrations.neverConnected');
}

/** What a Claude client's config on this computer holds of PryTax's server. */
export function probeText(status: ClaudeProbeStatus): string {
    switch (status) {
        case 'current':
            return t('settings.integrations.probeCurrent');
        case 'other_key':
            return t('settings.integrations.probeOtherKey');
        case 'no_key':
            return t('settings.integrations.probeNoKey');
        case 'none':
            return t('settings.integrations.probeNone');
        default:
            return t('settings.integrations.probeNoConfig');
    }
}
