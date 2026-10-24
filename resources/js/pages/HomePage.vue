<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Invoice, OutstandingTotal } from '@/api/types';
import OpenInvoiceRow from '@/components/invoice/OpenInvoiceRow.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { dateParts, formatMonthYear } from '@/utils/dates';
import { formatMoney } from '@/utils/money';

/**
 * Головна: the open invoices (ready and sent) by month of their period's end, newest month first, and within a month
 * by the period's start, the latest first (design §16, ordered as the mock).
 */
const { profile, profileId, path, loadProfiles } = useCurrentProfile();
const { can } = useSession();

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [invoices, outstanding] = await Promise.all([
        api.all<Invoice>(path('invoices'), {
            filter: { status: { in: ['ready', 'sent'] } },
            include: ['client'],
        }, signal),
        api.get<ApiResponse<OutstandingTotal[]>>(path('invoices/outstanding'), undefined, signal),
    ]);

    return { invoices, outstanding: outstanding.data };
}, [profileId]);

// The bell's count: notifications come and go with every write and the nightly check
onMounted(() => void loadProfiles().catch(() => undefined));

const months = computed(() => {
    const groups = new Map<string, { key: string; title: string; invoices: Invoice[] }>();

    for (const invoice of data.value?.invoices ?? []) {
        const { year, month } = dateParts(invoice.period_end);
        const key = `${year}-${String(month).padStart(2, '0')}`;

        if (!groups.has(key)) {
            groups.set(key, { key, title: formatMonthYear(year, month), invoices: [] });
        }

        groups.get(key)!.invoices.push(invoice);
    }

    return [...groups.values()]
        .sort((a, b) => b.key.localeCompare(a.key))
        .map((group) => ({
            ...group,
            invoices: group.invoices.sort((a, b) => b.period_start.localeCompare(a.period_start) || b.id - a.id),
        }));
});

const toPay = computed(() => (data.value?.outstanding ?? [])
    .filter((total) => Number(total.remaining) > 0)
    .map((total) => formatMoney(total.remaining, total.currency))
    .join(' + '));

const unread = computed(() => profile.value?.unread_alerts_count ?? 0);
</script>

<template>
    <TabScreen :title="t('shell.nav.home')">
        <template #action>
            <RouterLink
                :to="{ name: 'alerts' }"
                :aria-label="unread ? t('invoices.home.notificationsUnread', { count: unread }) : t('shell.alerts.notifications')"
                class="relative flex size-11 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-ink hover:text-ink"
            >
                <Icon name="bell" :size="22" />
                <span
                    v-if="unread"
                    class="absolute -top-0.5 -right-0.5 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-negative px-1 text-11 font-semibold text-white"
                >
                    {{ unread }}
                </span>
            </RouterLink>
        </template>

        <template #subtitle>
            <p v-if="data" class="m-0 text-14 text-ink-3">
                <Translated v-if="toPay" :text="t('invoices.home.openInvoicesOutstanding')">
                    <template #amount><span class="num">{{ toPay }}</span></template>
                </Translated>
                <template v-else>{{ t('invoices.home.openInvoices') }}</template>
            </p>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <EmptyState v-if="months.length === 0" :text="t('invoices.home.noOpenInvoices')">
                <RouterLink
                    v-if="can('create', 'invoices')"
                    :to="{ name: 'invoice-new' }"
                    class="flex min-h-11 items-center text-14 font-medium no-underline"
                >
                    {{ t('invoices.home.createInvoice') }}
                </RouterLink>
            </EmptyState>

            <section v-for="month in months" :key="month.key" :aria-labelledby="`month-${month.key}`" class="flex flex-col gap-2">
                <div class="flex items-baseline justify-between px-0.5">
                    <h2 :id="`month-${month.key}`" class="m-0 text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">
                        {{ month.title }}
                    </h2>
                    <span class="num text-13 text-ink-2">{{ t('core.counts.invoices', { count: month.invoices.length }) }}</span>
                </div>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <OpenInvoiceRow v-for="invoice in month.invoices" :key="invoice.id" :invoice="invoice" />
                </div>
            </section>

            <RouterLink :to="{ name: 'invoices' }" class="flex min-h-11 items-center self-center text-14 font-medium no-underline">
                {{ t('invoices.home.allInvoices') }}
            </RouterLink>
        </LoadState>
    </TabScreen>
</template>
