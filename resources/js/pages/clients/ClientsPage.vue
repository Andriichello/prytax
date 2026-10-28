<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '@/api/client';
import type { Client } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import NewPill from '@/components/ui/NewPill.vue';
import LoadState from '@/components/ui/LoadState.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { clientInitials } from '@/utils/clients';
import { daysBetween, formatDayMonth, today } from '@/utils/dates';
import { compareAmounts, formatMoney } from '@/utils/money';

/**
 * Клієнти (S-Clients): the active clients with their abbreviation, country, currency, contracts and invoices, and
 * what they owe — overdue in red. Inactive ones on request; the search finds a client by its abbreviation too.
 */
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const search = ref('');
const showInactive = ref(false);

const { data: clients, error, loading, reload } = useLoader(async (signal) => {
    const data = await api.all<Client>(path('clients'), { append: ['outstanding'], include: ['contracts'] }, signal);

    return data.sort((a, b) => a.name.localeCompare(b.name));
}, [profileId]);

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (clients.value ?? []).filter((client) => (showInactive.value || client.is_active)
        && (!term
            || client.name.toLowerCase().includes(term)
            || (client.legal_name ?? '').toLowerCase().includes(term)
            || client.abbreviation.toLowerCase().startsWith(term)));
});

const inactiveCount = computed(() => (clients.value ?? []).filter((client) => !client.is_active).length);

/** An active contract, which ends within a month: the row warns about it. */
function endingContract(client: Client): string | null {
    const ending = (client.contracts ?? [])
        .filter((contract) => contract.is_active && contract.ends_at && contract.ends_at >= today() && daysBetween(today(), contract.ends_at) <= 30)
        .sort((a, b) => (a.ends_at ?? '').localeCompare(b.ends_at ?? ''))[0];

    return ending?.ends_at ?? null;
}

function details(client: Client): string {
    const ends = endingContract(client);

    return [
        client.abbreviation,
        client.country,
        client.currency,
        ends ? t('clients.list.contractEnds', { date: formatDayMonth(ends) }) : client.contracts_count ? t('core.counts.contracts', { count: client.contracts_count }) : null,
        !ends && client.invoices_count ? t('core.counts.invoices', { count: client.invoices_count }) : null,
    ].filter(Boolean).join(' · ');
}

const owed = (client: Client) => (client.outstanding ?? []).filter((entry) => compareAmounts(entry.amount, '0') > 0);
const overdue = (client: Client) => (client.outstanding ?? []).some((entry) => compareAmounts(entry.overdue, '0') > 0);
</script>

<template>
    <SubScreen title="" muted width="list" :back="{ name: 'more' }">
        <div class="-mt-1 flex items-center justify-between gap-3">
            <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('core.common.clients') }}</h1>
            <NewPill v-if="can('create', 'clients')" :to="{ name: 'client-new' }" />
        </div>

        <div class="relative">
            <label for="client-search" class="sr-only">{{ t('clients.list.searchClients') }}</label>
            <Icon name="search" :size="20" class="pointer-events-none absolute top-3.5 left-3.5 text-ink-3" />
            <input
                id="client-search"
                v-model="search"
                type="search"
                :placeholder="t('clients.list.nameAbbreviation')"
                class="h-12 w-full rounded-control border border-line-input bg-surface pr-3.5 pl-11 text-16 placeholder:text-ink-3"
            >
        </div>

        <LoadState :loading="loading && !clients" :error="error" @retry="reload">
            <EmptyState v-if="clients && clients.length === 0" :text="t('clients.list.noClientsYet')">
                <RouterLink v-if="can('create', 'clients')" :to="{ name: 'client-new' }" class="flex min-h-11 items-center text-14 font-medium no-underline">
                    {{ t('clients.list.addClient') }}
                </RouterLink>
            </EmptyState>
            <EmptyState v-else-if="shown.length === 0" :text="t('core.common.nothingFound')" />

            <div v-else class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-3.5">
                <RouterLink
                    v-for="client in shown"
                    :key="client.id"
                    :to="{ name: 'client', params: { id: client.id } }"
                    class="flex items-center gap-3 py-3.5 no-underline"
                    :class="client.is_active ? 'text-ink hover:text-ink' : 'text-ink-3 hover:text-ink-3'"
                >
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-control text-15 font-semibold"
                        :class="client.is_active ? 'bg-line text-ink' : 'border border-dashed border-handle'"
                    >
                        {{ clientInitials(client.name) }}
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate text-17">{{ client.name }}</span>
                        <span class="text-13" :class="endingContract(client) ? 'text-warning-text' : 'text-ink-3'">
                            {{ client.is_active ? details(client) : [t('core.common.inactive'), client.abbreviation, client.country].filter(Boolean).join(' · ') }}
                        </span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-0.5">
                        <template v-if="owed(client).length">
                            <span v-for="entry in owed(client)" :key="entry.currency" class="num text-15 font-semibold">
                                {{ formatMoney(entry.amount, entry.currency) }}
                            </span>
                            <span class="text-12" :class="overdue(client) ? 'text-negative' : 'text-ink-3'">{{ overdue(client) ? t('core.common.overdue') : t('clients.list.outstanding') }}</span>
                        </template>
                        <span v-else class="num text-15 text-ink-3">0.00</span>
                    </span>
                </RouterLink>
            </div>

            <button
                v-if="inactiveCount > 0"
                type="button"
                class="flex min-h-11 items-center self-center text-15 font-medium text-accent"
                @click="showInactive = !showInactive"
            >
                {{ showInactive ? t('clients.list.hideInactive') : t('clients.list.showInactive', { count: inactiveCount }) }}
            </button>
        </LoadState>
    </SubScreen>
</template>
