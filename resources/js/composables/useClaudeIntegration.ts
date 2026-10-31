import { ref, watch } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, ClaudeConnection, ClaudeIntegration, ClaudeProbe } from '@/api/types';
import { useLoader } from '@/composables/useLoader';

const URL = 'organization/integrations/claude';

/** What the Claude integration's page is busy with. */
export type ClaudeAction = 'switch' | 'writes' | 'access' | 'key' | 'probe' | 'connection';

/**
 * The organization's Claude integration (builds.md §7), for its page: its state, turning it on and off and configuring
 * it (`PUT`), its connection, which is loaded while it's on, a new key, and the probe of this computer.
 */
export function useClaudeIntegration() {
    const { data: integration, error, loading, reload } = useLoader(
        async (signal) => (await api.get<ApiResponse<ClaudeIntegration>>(URL, undefined, signal)).data,
        [],
    );
    const connection = ref<ClaudeConnection | null>(null);
    const probe = ref<ClaudeProbe | null>(null);
    const busy = ref<ClaudeAction | null>(null);
    const failure = ref<unknown>(null);

    /** Run an action, which is the page's only one meanwhile, keeping its failure. */
    async function act(action: ClaudeAction, run: () => Promise<void>): Promise<boolean> {
        busy.value = action;
        failure.value = null;

        try {
            await run();

            return true;
        } catch (caught) {
            failure.value = caught;

            return false;
        } finally {
            busy.value = null;
        }
    }

    const loadConnection = () => act('connection', async () => {
        connection.value = (await api.get<ApiResponse<ClaudeConnection>>(`${URL}/connection`)).data;
    });

    /** Turn it on or off, allow changes or not, or change its acting user and ФОП; the connection and probe go stale. */
    const configure = (fields: { enabled?: boolean; writes?: boolean; acting_user_id?: number | null; profile_ids?: number[] | null }) =>
        act(fields.enabled !== undefined ? 'switch' : fields.writes !== undefined ? 'writes' : 'access', async () => {
            await api.put(URL, fields);
            connection.value = null;
            probe.value = null;
            await reload();
        });

    /** A new key: the old one stops working, so the connection is shown again. */
    const rekey = () => act('key', async () => {
        await api.post(`${URL}/key`);
        connection.value = null;
        probe.value = null;
        await reload();
    });

    const runProbe = () => act('probe', async () => {
        probe.value = (await api.post<ApiResponse<ClaudeProbe>>(`${URL}/probe`)).data;
    });

    // The connection is the integration's while it's on
    watch(() => [integration.value?.enabled, integration.value?.key_hint], ([enabled]) => {
        if (enabled && connection.value === null && busy.value !== 'connection') {
            void loadConnection();
        }
    }, { immediate: true });

    return { integration, error, loading, reload, connection, probe, busy, failure, configure, rekey, runProbe };
}
