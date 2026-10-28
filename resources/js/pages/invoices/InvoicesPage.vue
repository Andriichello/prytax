<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Invoice, InvoiceFacets, Paginated } from '@/api/types';
import InvoiceFiltersSheet from '@/components/invoice/InvoiceFiltersSheet.vue';
import InvoiceRow from '@/components/invoice/InvoiceRow.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import NewPill from '@/components/ui/NewPill.vue';
import InfiniteSentinel from '@/components/ui/InfiniteSentinel.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { countFilters, emptyFilters, filtersToApi, statusFilterLabel, useInvoiceFilters } from '@/composables/useInvoiceFilters';
import { useLoader } from '@/composables/useLoader';
import { usePagedList } from '@/composables/usePagedList';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { dateParts, monthName, monthNameGenitive, quarterLabel, quarterOf } from '@/utils/dates';

/**
 * Інвойси: every invoice, newest first, grouped by the month of issue («IV кв. · Жовтень · 2026») with its count («N інвойсів», or «N записи» with
 * a payment without an invoice among them), in the rows of Головна, loading further as the list scrolls. The last
 * month on screen shows its count once it is loaded whole. Filters (no free-text search) live in the URL and open the
 * filters sheet. App keeps it alive behind an opened invoice.
 */
defineOptions({ name: 'InvoicesPage' });

const { profileId, path } = useCurrentProfile();
const { can } = useSession();
const { filters, periodSummary, apply } = useInvoiceFilters();

const filtering = ref(false);

const { data: facets } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<InvoiceFacets>>(path('invoices/facets'), undefined, signal);

    return data;
}, [profileId]);

const list = usePagedList<Invoice>((page, signal) => api.get<Paginated<Invoice>>(path('invoices'), {
    filter: filtersToApi(filters.value),
    include: ['client', 'receivingAccount'],
    append: ['number_preview'],
    page: { number: page, size: 30 },
}, signal), [profileId, filters]);

const months = computed(() => {
    const groups: { key: string; title: string; invoices: Invoice[] }[] = [];

    for (const invoice of list.items.value) {
        const { year, month } = dateParts(invoice.issued_at);
        const key = `${year}-${month}`;
        const last = groups[groups.length - 1];

        if (last?.key === key) {
            last.invoices.push(invoice);
        } else {
            groups.push({ key, title: `${quarterLabel(quarterOf(month))} · ${monthName(month)} · ${year}`, invoices: [invoice] });
        }
    }

    // The last month may go on in the next page: it counts once that has loaded
    return groups.map((group, index) => ({
        ...group,
        count: index < groups.length - 1 || !list.hasMore.value
            ? group.invoices.some((invoice) => invoice.source === 'virtual')
                ? t('core.counts.records', { count: group.invoices.length })
                : t('core.counts.invoices', { count: group.invoices.length })
            : null,
    }));
});

/** "липня 2026": the month the next page continues, for the loading line at the bottom. */
const loadingMonth = computed(() => {
    const last = list.items.value.at(-1);

    if (!last) {
        return null;
    }

    const { year, month } = dateParts(last.issued_at);

    return `${monthNameGenitive(month)} ${year}`;
});

function chipLabel(kind: 'client' | 'status' | 'currency'): string {
    const { clients, statuses, currencies } = filters.value;

    if (kind === 'client') {
        const name = facets.value?.clients.find((client) => client.client_id === clients[0])?.name;

        return clients.length === 1 && name ? name : clients.length ? t('invoices.list.clientsCount', { count: clients.length }) : t('invoices.list.allClients');
    }

    if (kind === 'status') {
        return statuses.length === 1 ? statusFilterLabel(statuses[0]!) : statuses.length ? t('invoices.list.statusCount', { count: statuses.length }) : t('invoices.list.allStatuses');
    }

    return currencies.length ? currencies.join(', ') : t('invoices.list.allCurrencies');
}

const chips = computed(() => [
    { key: 'client', label: chipLabel('client'), active: filters.value.clients.length > 0 },
    { key: 'status', label: chipLabel('status'), active: filters.value.statuses.length > 0 },
    ...((facets.value?.currencies.length ?? 0) > 1
        ? [{ key: 'currency', label: chipLabel('currency'), active: filters.value.currencies.length > 0 }]
        : []),
]);

const chipClass = (active: boolean): string => (active
    ? 'border-ink bg-ink font-medium text-white'
    : 'border-line-input bg-surface text-ink');
</script>

<template>
    <TabScreen :title="t('shell.nav.invoices')">
        <template #title-action>
            <NewPill v-if="can('create', 'invoices')" :to="{ name: 'invoice-new' }" />
        </template>
        <template #filters>
            <nav :aria-label="t('invoices.list.filters')" class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4">
                <button
                    type="button"
                    class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border pr-3 pl-3.5 text-14"
                    :class="chipClass(periodSummary !== null)"
                    @click="filtering = true"
                >
                    <Icon name="calendar" :size="16" />
                    <span :class="periodSummary && /^\d/.test(periodSummary) ? 'num' : ''">{{ periodSummary ?? t('core.common.period') }}</span>
                    <Icon name="chevronDown" :size="16" />
                </button>
                <button
                    v-for="chip in chips"
                    :key="chip.key"
                    type="button"
                    class="flex min-h-11 max-w-56 shrink-0 items-center gap-1.5 rounded-full border pr-3 pl-3.5 text-14"
                    :class="chipClass(chip.active)"
                    @click="filtering = true"
                >
                    <span class="truncate">{{ chip.label }}</span>
                    <Icon name="chevronDown" :size="16" />
                </button>
                <button
                    v-if="countFilters(filters) > 0"
                    type="button"
                    class="flex min-h-11 shrink-0 items-center px-2 text-14 font-medium text-accent"
                    @click="apply(emptyFilters())"
                >
                    {{ t('invoices.list.reset') }}
                </button>
            </nav>
        </template>

        <LoadState :loading="list.loading.value && list.items.value.length === 0" :error="list.error.value" @retry="list.reload">
            <EmptyState
                v-if="!list.loading.value && list.items.value.length === 0"
                :text="countFilters(filters) ? t('invoices.list.noInvoicesMatch') : t('invoices.list.noInvoicesYet')"
            >
                <RouterLink
                    v-if="!countFilters(filters) && can('create', 'invoices')"
                    :to="{ name: 'invoice-new' }"
                    class="flex min-h-11 items-center text-14 font-medium no-underline"
                >
                    {{ t('invoices.list.createFirstInvoice') }}
                </RouterLink>
            </EmptyState>

            <section v-for="month in months" :key="month.key" class="flex flex-col gap-2" :aria-label="month.title">
                <SectionTitle class="mt-1.5">
                    {{ month.title }}
                    <template v-if="month.count" #aside><span class="num text-ink-2">{{ month.count }}</span></template>
                </SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <InvoiceRow v-for="invoice in month.invoices" :key="invoice.id" :invoice="invoice" />
                </div>
            </section>

            <InfiniteSentinel v-if="list.hasMore.value" :loading="list.loadingMore.value" :failed="Boolean(list.moreError.value)" @visible="list.loadMore">
                {{ loadingMonth ? t('invoices.list.loadingMonth', { month: loadingMonth }) : t('core.common.loading') }}
            </InfiniteSentinel>
        </LoadState>

        <InvoiceFiltersSheet v-model:open="filtering" :facets="facets" :filters="filters" @apply="apply" />
    </TabScreen>
</template>
