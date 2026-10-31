import { describe, expect, it } from 'vitest';
import type { ClaudeIntegration } from '@/api/types';
import { clientName, connectedLine, probeText, switchedLine, writesLine } from '@/utils/integrations';

const integration = (fields: Partial<ClaudeIntegration>): ClaudeIntegration => ({
    kind: 'claude', enabled: false, enabled_at: null, enabled_by: null, disabled_at: null, disabled_by: null,
    acting_user_id: null, acting_user: null, profile_ids: null, writes_enabled: false, writes_enabled_at: null, writes_enabled_by: null,
    key_hint: null, key_created_at: null,
    last_connected_at: null, last_client: null, last_used_at: null, last_tool: null, context: 'desktop', probe_available: true,
    ...fields,
});

describe('clientName', () => {
    it('names Claude Code and Claude Desktop by their clients, and any other by its own name', () => {
        expect(clientName({ name: 'claude-code', version: '2.1.316' })).toBe('Claude Code');
        expect(clientName({ name: 'claude-ai', version: '0.9.0' })).toBe('Claude Desktop');
        expect(clientName({ name: 'prytax-starter' })).toBe('prytax-starter');
    });
});

describe('switchedLine', () => {
    it('tells who turned it on and when, or nothing before anyone did', () => {
        const on = integration({ enabled: true, enabled_at: '2026-10-31T20:40:00Z', enabled_by: { id: 1, name: 'Олена Коваленко', email: 'olena@example.com' } });

        expect(switchedLine(on)).toBe('Увімкнено 31.10.2026, 22:40 · Олена Коваленко');
        expect(switchedLine(integration({}))).toBeNull();
    });
});

describe('writesLine', () => {
    it('tells who allowed changes and when, or nothing while Claude only reads', () => {
        const allowed = integration({
            enabled: true, writes_enabled: true, writes_enabled_at: '2026-10-31T21:55:00Z',
            writes_enabled_by: { id: 1, name: 'Олена Коваленко', email: 'olena@example.com' },
        });

        expect(writesLine(allowed)).toBe('Зміни дозволено 31.10.2026, 23:55 · Олена Коваленко');
        expect(writesLine(integration({ enabled: true }))).toBeNull();
    });
});

describe('connectedLine', () => {
    it('tells that Claude never connected', () => {
        expect(connectedLine(integration({}))).toBe('Claude ще не підключався.');
    });
});

describe('probeText', () => {
    it('tells a config with this key from one with another', () => {
        expect(probeText('current')).toBe('налаштовано з цим ключем');
        expect(probeText('other_key')).toBe('налаштовано з іншим ключем — вставте підключення знову');
    });
});
