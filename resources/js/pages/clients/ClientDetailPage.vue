<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, ChargeType, Client, ClientStats, Contract, Project } from '@/api/types';
import ClientItemSheet from '@/components/client/ClientItemSheet.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { countryName } from '@/utils/clients';
import { rateOn } from '@/utils/contracts';
import { formatDate, today } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';

/**
 * Клієнт (S-ClientDetail): the abbreviation beside the legal name, what the client paid (all time, this year, last
 * year) and still owes, per currency, then its contracts, projects, work types and requisites. Clients are
 * deactivated, never deleted.
 */
const route = useRoute();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [client, stats] = await Promise.all([
        api.get<ApiResponse<Client>>(path(`clients/${id.value}`), { include: ['contracts.rates', 'projects', 'chargeTypes'] }, signal),
        api.get<ApiResponse<ClientStats>>(path(`clients/${id.value}/stats`), undefined, signal),
    ]);

    return { client: client.data, stats: stats.data };
}, [profileId, id]);

const client = computed(() => data.value?.client ?? null);
const stats = computed(() => data.value?.stats ?? null);
const chargeTypes = computed(() => client.value?.chargeTypes ?? []);

function contractState(contract: Contract): { label: string; tone: 'positive' | 'neutral' | 'warning' } {
    if (!contract.is_active) {
        return { label: t('core.common.inactive'), tone: 'neutral' };
    }

    if (contract.ends_at && contract.ends_at < today()) {
        return { label: t('clients.detail.ended'), tone: 'neutral' };
    }

    return contract.started_at > today() ? { label: t('clients.detail.notYetEffect'), tone: 'warning' } : { label: t('clients.detail.inEffect'), tone: 'positive' };
}

/** The contract's rates in effect today: one per work type, and the general one when no type has its own. */
function currentRates(contract: Contract): string {
    const rates = contract.rates ?? [];
    const typed = chargeTypes.value
        .map((type) => ({ type, rate: rateOn(rates.filter((rate) => rate.charge_type_id === type.id), today(), type.id) }))
        .filter((entry) => entry.rate !== null)
        .map((entry) => t('clients.detail.typeRatePerHour', { type: entry.type.name, rate: formatAmount(entry.rate!.hourly_rate) }));

    if (typed.length) {
        return typed.join(' · ');
    }

    const general = rateOn(rates, today(), null);

    return general ? t('clients.detail.ratePerHour', { rate: formatAmount(general.hourly_rate) }) : t('clients.detail.noRates');
}

const sheet = ref<{ kind: 'project' | 'chargeType'; item: Project | ChargeType | null } | null>(null);
const sheetOpen = ref(false);

function openSheet(kind: 'project' | 'chargeType', item: Project | ChargeType | null = null): void {
    sheet.value = { kind, item };
    sheetOpen.value = true;
}

const toggling = ref(false);
const toggleError = ref<unknown>(null);

async function toggleActive(): Promise<void> {
    if (!client.value) {
        return;
    }

    toggling.value = true;
    toggleError.value = null;

    try {
        await api.patch(path(`clients/${id.value}`), { is_active: !client.value.is_active });
        await reload();
    } catch (caught) {
        toggleError.value = caught;
    } finally {
        toggling.value = false;
    }
}

const addressLines = computed(() => {
    const address = client.value?.address;

    if (!address) {
        return [];
    }

    return [
        ...(address.lines ?? []),
        [address.city, address.state, address.zip].filter(Boolean).join(', '),
        countryName(client.value?.country),
    ].filter((line): line is string => !!line);
});
</script>

<template>
    <SubScreen :title="t('core.common.client')" muted :back="{ name: 'clients' }">
        <template #action>
            <RouterLink
                v-if="client && can('update', 'clients')"
                :to="{ name: 'client-edit', params: { id } }"
                class="flex min-h-11 items-center px-3 text-15 font-medium no-underline"
            >
                {{ t('core.common.edit') }}
            </RouterLink>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="client && stats">
                <div class="-mt-1 flex flex-col gap-1">
                    <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]" :class="client.is_active ? '' : 'text-ink-3'">{{ client.name }}</h1>
                    <span class="text-15 text-ink-3">
                        <span class="num">{{ client.abbreviation }}</span> · {{ [client.legal_name, countryName(client.country)].filter(Boolean).join(' · ') }}<template v-if="!client.is_active"> · {{ t('clients.detail.inactive') }}</template>
                    </span>
                </div>

                <section
                    v-for="currency in stats.currencies"
                    :key="currency.currency"
                    class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4"
                    :aria-label="t('clients.detail.paymentsIn', { currency: currency.currency })"
                >
                    <div class="flex items-start justify-between gap-3 py-3.5">
                        <span class="flex flex-col gap-0.5">
                            <span class="text-15 text-ink-2">{{ t('clients.detail.paidAllTime') }}</span>
                            <span v-if="stats.since" class="text-12 text-ink-3">{{ t('core.common.fromDate', { date: formatDate(stats.since) }) }}</span>
                        </span>
                        <span class="num text-18 font-bold">{{ formatMoney(currency.paid_all_time, currency.currency) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3 text-15">
                        <span class="text-ink-2">{{ t('clients.detail.paidIn', { year: stats.year }) }}</span>
                        <span class="num font-semibold">{{ formatMoney(currency.paid_this_year, currency.currency) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3 text-15">
                        <span class="text-ink-2">{{ t('clients.detail.paidIn', { year: stats.year - 1 }) }}</span>
                        <span class="num font-semibold">{{ formatMoney(currency.paid_previous_year, currency.currency) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3 text-15">
                        <span class="text-ink-2">{{ t('clients.detail.invoicedNotPaid') }}</span>
                        <span v-if="compareAmounts(currency.outstanding, '0') > 0" class="num font-semibold" :class="compareAmounts(currency.overdue, '0') > 0 ? 'text-negative' : 'text-warning-text'">
                            {{ formatMoney(currency.outstanding, currency.currency) }}
                        </span>
                        <span v-else class="flex items-center gap-1 font-semibold text-positive"><Icon name="check" :size="18" /> {{ t('clients.detail.allPaid') }}</span>
                    </div>
                </section>
                <p v-if="stats.currencies.length === 0" class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-3">{{ t('clients.detail.noPayments') }}</p>

                <RouterLink
                    v-if="stats.invoices_count"
                    :to="{ name: 'invoices', query: { client: client.id } }"
                    class="flex min-h-11 items-center gap-1 self-start text-15 font-medium no-underline"
                >
                    {{ t('clients.detail.invoicesCount', { count: stats.invoices_count }) }} <Icon name="chevronRight" :size="18" />
                </RouterLink>

                <section class="flex flex-col gap-2">
                    <SectionTitle>
                        {{ t('clients.detail.contracts') }}
                        <template v-if="can('create', 'contracts')" #aside>
                            <RouterLink :to="{ name: 'contract-new', params: { clientId: client.id } }" class="flex min-h-11 items-center text-15 font-medium no-underline">{{ t('core.common.plusAdd') }}</RouterLink>
                        </template>
                    </SectionTitle>
                    <RouterLink
                        v-for="contract in client.contracts ?? []"
                        :key="contract.id"
                        :to="{ name: 'contract', params: { clientId: client.id, id: contract.id } }"
                        class="flex flex-col gap-1 rounded-card border border-line bg-surface p-4 text-ink no-underline hover:text-ink"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span class="text-16 font-semibold">{{ contract.name }}<template v-if="contract.number"> {{ t('clients.detail.numberSign', { number: contract.number }) }}</template></span>
                            <StatusBadge v-bind="contractState(contract)" />
                        </span>
                        <span class="text-13 text-ink-3">
                            {{ t('core.common.fromDate', { date: formatDate(contract.started_at) }) }} · {{ contract.ends_at ? t('core.common.untilDate', { date: formatDate(contract.ends_at) }) : t('clients.detail.openEnded') }} · {{ contract.currency }}
                        </span>
                        <span class="text-13 text-ink-2">{{ currentRates(contract) }}</span>
                    </RouterLink>
                    <p v-if="!client.contracts?.length" class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-3">
                        {{ t('clients.detail.noContractNote') }}
                    </p>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>
                        {{ t('clients.detail.projects') }}
                        <template v-if="can('create', 'projects')" #aside>
                            <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openSheet('project')">{{ t('core.common.plusAdd') }}</button>
                        </template>
                    </SectionTitle>
                    <div v-if="client.projects?.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <button
                            v-for="project in client.projects"
                            :key="project.id"
                            type="button"
                            class="flex min-h-13 items-center gap-3 text-left"
                            :disabled="!can('update', 'projects')"
                            @click="openSheet('project', project)"
                        >
                            <span
                                class="num rounded-md px-2 py-[3px] text-12 font-semibold"
                                :class="project.is_active ? 'bg-ink text-white' : 'border border-line-input text-ink-2'"
                            >
                                {{ project.code }}
                            </span>
                            <span class="text-15" :class="project.is_active ? '' : 'text-ink-3'">
                                {{ project.name }}<template v-if="!project.is_active"> · {{ t('clients.detail.inactive') }}</template>
                            </span>
                        </button>
                    </div>
                    <p v-else class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-3">{{ t('clients.detail.noProjects') }}</p>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>
                        {{ t('clients.detail.workTypes') }}
                        <template v-if="can('create', 'charge-types')" #aside>
                            <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openSheet('chargeType')">{{ t('core.common.plusAdd') }}</button>
                        </template>
                    </SectionTitle>
                    <div v-if="chargeTypes.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="type in chargeTypes"
                            :key="type.id"
                            type="button"
                            class="flex min-h-11 items-center rounded-full border border-line-input bg-surface px-4 text-15"
                            :class="type.is_active ? '' : 'text-ink-3 line-through'"
                            :disabled="!can('update', 'charge-types')"
                            @click="openSheet('chargeType', type)"
                        >
                            {{ type.name }}
                        </button>
                    </div>
                    <p v-else class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-3">{{ t('clients.detail.noWorkTypes') }}</p>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>{{ t('clients.detail.clientDetails') }}</SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4 text-15">
                        <div class="flex flex-col gap-1 py-3">
                            <span class="text-12 text-ink-3">{{ t('core.common.address') }}</span>
                            <span v-for="line in addressLines" :key="line">{{ line }}</span>
                            <span v-if="!addressLines.length" class="text-ink-3">—</span>
                        </div>
                        <div class="flex justify-between gap-3 py-3">
                            <span class="text-ink-2">{{ t('clients.detail.taxNumber') }}</span>
                            <span class="num">{{ client.tax_id || client.vat_number || '—' }}</span>
                        </div>
                        <div class="flex justify-between gap-3 py-3">
                            <span class="shrink-0 text-ink-2">{{ t('clients.detail.invoiceEmail') }}</span>
                            <span class="truncate">{{ client.email || '—' }}</span>
                        </div>
                        <div class="flex justify-between gap-3 py-3">
                            <span class="shrink-0 text-ink-2">{{ t('clients.detail.invoiceNote') }}</span>
                            <span class="text-right">{{ client.invoice_footer_note || '—' }}</span>
                        </div>
                    </div>
                </section>

                <p v-if="toggleError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(toggleError) }}</p>
                <template v-if="can('update', 'clients')">
                    <SecondaryButton :tone="client.is_active ? 'negative' : 'default'" :busy="toggling" class="bg-muted" @click="toggleActive">
                        {{ client.is_active ? t('clients.detail.deactivateClient') : t('clients.detail.activateClient') }}
                    </SecondaryButton>
                    <p class="-mt-2 m-0 text-center text-13 text-ink-3">{{ t('clients.detail.notDeletedNote') }}</p>
                </template>
            </template>
        </LoadState>

        <ClientItemSheet
            v-if="sheet && client"
            v-model:open="sheetOpen"
            :kind="sheet.kind"
            :client-id="client.id"
            :item="sheet.item"
            @saved="reload"
        />
    </SubScreen>
</template>
