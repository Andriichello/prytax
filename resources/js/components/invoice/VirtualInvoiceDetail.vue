<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, Invoice } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import InvoicePickerSheet from '@/components/payment/InvoicePickerSheet.vue';
import ReceiptTaxCard from '@/components/payment/ReceiptTaxCard.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import InfoRow from '@/components/ui/InfoRow.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatDate, formatDayMonth, formatPeriod } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { formatMoney } from '@/utils/money';

/**
 * Запис «Без інвойсу» (M-VInvoiceDetail, design.md §4.2, §16.5): a payment under the contract, which has no invoice.
 * The marker with its payment date, the client, the amount (always its receipt's), the contract, the dates, the
 * account and «За що»; the receipt card with its taxes; and, if the client asks for an invoice, «Оформити як інвойс»
 * (the next number and a PDF, still paid) or «Прив’язати до виставленого інвойсу» (the receipt moves to it, and the
 * record goes).
 */
const { invoice } = defineProps<{ invoice: Invoice }>();
const emit = defineEmits<{ changed: [] }>();

const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const payment = computed(() => invoice.payments?.[0] ?? null);
/** The receipt, with its invoice as the receipt card names it. */
const receipt = computed(() => (payment.value ? { ...payment.value, invoice: { ...invoice, payments: undefined } } : null));

const menuOpen = ref(false);

/* The number, which issuing would give: the next one of the client's counter, by the payment date */
const { data: nextNumber } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<{ number: string }>>(path('invoices/number-preview'), {
        client_id: invoice.client_id,
        period_end: invoice.period_end,
        issued_at: invoice.issued_at,
    }, signal);

    return data.number;
}, [profileId, () => invoice.id]);

/* Issuing */
const issueOpen = ref(false);
const issuing = ref(false);
const issueError = ref<string | null>(null);

async function issue(): Promise<void> {
    issuing.value = true;
    issueError.value = null;

    try {
        await api.post(path(`invoices/${invoice.id}/issue`), {});
        issueOpen.value = false;
        emit('changed');
    } catch (caught) {
        issueError.value = errorMessage(caught);
    } finally {
        issuing.value = false;
    }
}

/* Attaching the receipt to an issued invoice of the client in its currency */
const attachOpen = ref(false);
const attachError = ref<string | null>(null);
const { data: issued } = useLoader((signal) => api.all<Invoice>(path('invoices'), {
    filter: { status: { in: ['ready', 'sent', 'paid'] }, currency: invoice.currency, client_id: invoice.client_id },
    include: ['client'],
    sort: '-issued_at',
}, signal), [profileId, () => invoice.id]);

async function attach(target: Invoice): Promise<void> {
    attachError.value = null;

    try {
        await api.patch(path(`payments/${payment.value!.id}`), { invoice_id: target.id });
        await router.replace({ name: 'invoice', params: { id: target.id } });
    } catch (caught) {
        attachError.value = errorMessage(caught);
    }
}
</script>

<template>
    <SubScreen :title="t('invoices.virtual.record')" muted :back="{ name: 'invoices' }">
        <template v-if="payment" #action>
            <button
                type="button"
                :aria-label="t('core.common.moreActions')"
                class="flex size-11 items-center justify-center text-ink"
                @click="menuOpen = true"
            >
                <Icon name="more" :size="22" />
            </button>
        </template>

        <div class="flex flex-col gap-1.5">
            <span class="flex items-center gap-2">
                <VirtualMarker />
                <StatusBadge :label="`${t('core.invoiceStatus.paid')} ${formatDayMonth(invoice.paid_at)}`" tone="positive" />
            </span>
            <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('invoices.virtual.byContract') }}</h1>
            <span class="text-16 text-ink">{{ invoice.client?.name }}</span>
        </div>

        <section class="flex flex-col gap-1 rounded-card border border-line bg-surface px-4 py-3.5">
            <span class="flex items-baseline justify-between gap-3">
                <span class="text-14 text-ink-2">{{ t('invoices.virtual.fromClient') }}</span>
                <span class="text-12 text-ink-3">{{ t('invoices.virtual.equalsReceipts') }}</span>
            </span>
            <span class="num text-26 font-semibold tracking-[-0.02em]">{{ formatMoney(invoice.total, invoice.currency) }}</span>
        </section>

        <section class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
            <InfoRow v-if="invoice.contract" :label="t('core.common.contract')">
                {{ invoice.contract.name }} · {{ formatDate(invoice.contract.started_at) }}
            </InfoRow>
            <InfoRow :label="t('invoices.virtual.paidOn')" figure>{{ formatDate(invoice.paid_at) }}</InfoRow>
            <InfoRow v-if="invoice.receivingAccount" :label="t('invoices.detail.receivingAccount')">{{ invoice.receivingAccount.name }}</InfoRow>
            <InfoRow :label="t('invoices.detail.servicePeriod')" figure>{{ formatPeriod(invoice.period_start, invoice.period_end) }}</InfoRow>
            <InfoRow v-if="invoice.notes" :label="t('invoices.virtual.whatFor')">{{ invoice.notes }}</InfoRow>
        </section>

        <section v-if="receipt" class="flex flex-col gap-2.5">
            <SectionTitle>{{ t('core.common.receipt') }}</SectionTitle>
            <ReceiptTaxCard :payment="receipt" />
        </section>

        <section class="flex flex-col gap-1.5 rounded-card bg-muted p-4 text-14 leading-[1.45]">
            <span class="flex items-center gap-2 font-semibold">
                <Icon name="info" :size="18" />
                {{ t('invoices.virtual.booksOnly') }}
            </span>
            <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-ink-2">
                <li>{{ t('invoices.virtual.noDocument') }}</li>
                <li>{{ t('invoices.virtual.followsReceipt') }}</li>
                <li>{{ t('invoices.virtual.goesWithReceipt') }}</li>
            </ul>
        </section>

        <section v-if="payment && can('update', 'payments')" class="flex flex-col gap-2.5">
            <SectionTitle>{{ t('invoices.virtual.ifAsked') }}</SectionTitle>
            <button
                v-if="can('issue', 'invoices')"
                type="button"
                class="flex flex-col gap-1 rounded-card bg-accent px-4 py-3.5 text-left text-white hover:bg-accent-hover"
                @click="issueOpen = true"
            >
                <span class="text-17 font-semibold">{{ t('invoices.virtual.issue') }}</span>
                <span class="text-13 text-white/85">{{ t('invoices.virtual.issueExplained', { number: nextNumber ?? '…' }) }}</span>
            </button>
            <button
                type="button"
                class="flex flex-col gap-1 rounded-card border border-line-input bg-surface px-4 py-3.5 text-left text-ink"
                @click="attachOpen = true"
            >
                <span class="text-17 font-semibold">{{ t('invoices.virtual.attach') }}</span>
                <span class="text-13 text-ink-2">{{ t('invoices.virtual.attachExplained') }}</span>
            </button>
            <p v-if="attachError" class="m-0 text-14 text-negative" role="alert">{{ attachError }}</p>
        </section>

        <BottomSheet v-if="payment" v-model:open="menuOpen" :title="t('money.payment.receiptActions')">
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <RouterLink
                    :to="{ name: 'payment', params: { id: payment.id } }"
                    class="flex min-h-13 items-center gap-3 text-15 text-ink no-underline hover:text-ink"
                >
                    <Icon name="ledger" :size="20" /> {{ t('invoices.virtual.openReceipt') }}
                </RouterLink>
                <RouterLink
                    v-if="can('update', 'payments')"
                    :to="{ name: 'payment-edit', params: { id: payment.id } }"
                    class="flex min-h-13 items-center gap-3 text-15 text-ink no-underline hover:text-ink"
                >
                    <Icon name="invoice" :size="20" /> {{ t('money.payment.edit') }}
                </RouterLink>
            </div>
        </BottomSheet>

        <ConfirmSheet
            v-model:open="issueOpen"
            :title="t('invoices.virtual.issueQuestion')"
            :confirm-label="t('invoices.virtual.issue')"
            :busy="issuing"
            :error="issueError"
            @confirm="issue"
        >
            <p class="m-0">{{ t('invoices.virtual.issueExplained', { number: nextNumber ?? '…' }) }}</p>
        </ConfirmSheet>

        <InvoicePickerSheet
            v-model:open="attachOpen"
            :invoices="issued ?? []"
            :selected-id="null"
            :title="t('invoices.virtual.attach')"
            :empty-text="t('invoices.virtual.noIssued', { currency: invoice.currency })"
            @select="attach"
        />
    </SubScreen>
</template>
