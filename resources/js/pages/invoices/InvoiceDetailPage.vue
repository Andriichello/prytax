<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { ApiResponse, Invoice } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import InvoiceItemCard from '@/components/invoice/InvoiceItemCard.vue';
import InvoiceStepper from '@/components/invoice/InvoiceStepper.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ReceiptCard from '@/components/payment/ReceiptCard.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import InfoRow from '@/components/ui/InfoRow.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { formatDate, formatDayMonth, formatPeriod, kyivDate, today } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, percentOf, subtractAmounts } from '@/utils/money';
import { INVOICE_STATUSES } from '@/utils/statuses';

/**
 * Інвойс (S-InvoiceDetail): status and how much is received, the receipts, the dates, items and document, an
 * editable note, and the status changes (sending, paying and cancelling ask for their date, today by default; paying
 * defaults to the last receipt's date). A draft opens in its editor instead.
 */
type Action = 'send' | 'pay' | 'cancel' | 'unfreeze';

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));

const { data: invoice, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<Invoice>>(path(`invoices/${id.value}`), {
        include: ['client', 'contract', 'receivingAccount', 'items', 'payments.receivedToAccount', 'primaryFile'],
        append: ['can_unfreeze'],
    }, signal);

    return data;
}, [profileId, id]);

watch(invoice, (loaded) => {
    if (loaded?.status === 'draft') {
        void router.replace({ name: 'invoice-edit', params: { id: loaded.id } });
    }
});

const paid = computed(() => invoice.value?.paid_amount ?? '0.00');
const remaining = computed(() => (invoice.value ? subtractAmounts(invoice.value.total, paid.value) : '0.00'));
const percent = computed(() => (invoice.value ? percentOf(paid.value, invoice.value.total) : 0));
const isOpen = computed(() => invoice.value?.status === 'ready' || invoice.value?.status === 'sent');
const covered = computed(() => invoice.value !== null && compareAmounts(paid.value, invoice.value.total) >= 0);
const overdue = computed(() => isOpen.value && !covered.value && invoice.value?.due_at != null && invoice.value.due_at < today());
const statusDate = computed(() => {
    const value = invoice.value;

    if (!value) {
        return '';
    }

    const date = { sent: value.sent_at, paid: value.paid_at, cancelled: value.cancelled_at }[value.status as 'sent' | 'paid' | 'cancelled'];

    return date ? ` ${formatDayMonth(date)}` : '';
});
const sortedPayments = computed(() => [...(invoice.value?.payments ?? [])].sort((a, b) => a.received_at.localeCompare(b.received_at)));
const fileSize = computed(() => {
    const size = invoice.value?.primaryFile?.size;

    return size ? `${Math.max(1, Math.round(size / 1024))} КБ` : '';
});
const pdfUrl = computed(() => apiUrl(path(`invoices/${id.value}/pdf`)));

const notes = ref('');
const savingNotes = ref(false);
const notesError = ref<string | null>(null);

watch(invoice, (loaded) => {
    notes.value = loaded?.notes ?? '';
});

/**
 * Save the note (the only field a frozen invoice changes), guarded against someone else's newer change.
 */
async function saveNotes(): Promise<void> {
    if (!invoice.value || notes.value === (invoice.value.notes ?? '')) {
        return;
    }

    savingNotes.value = true;
    notesError.value = null;

    try {
        const { data } = await api.patch<ApiResponse<Invoice>>(path(`invoices/${id.value}`), { notes: notes.value || null }, {
            'If-Unmodified-Since': invoice.value.updated_at,
        });

        invoice.value = { ...invoice.value, notes: data.notes, updated_at: data.updated_at };
    } catch (caught) {
        notesError.value = errorMessage(caught);
    } finally {
        savingNotes.value = false;
    }
}

const menuOpen = ref(false);
const confirmOpen = ref(false);
const confirming = ref<Action>('send');
const acting = ref(false);
const actionError = ref<string | null>(null);

/** The date of the status change: `sent_at`, `paid_at` or `cancelled_at`. */
const actionDate = ref('');

const DATE_FIELDS: Partial<Record<Action, { field: string; label: string }>> = {
    send: { field: 'sent_at', label: 'Дата надсилання' },
    pay: { field: 'paid_at', label: 'Дата оплати' },
    cancel: { field: 'cancelled_at', label: 'Дата скасування' },
};

function ask(action: Action): void {
    menuOpen.value = false;
    actionError.value = null;
    confirming.value = action;
    actionDate.value = action === 'pay' ? sortedPayments.value.at(-1)?.received_at ?? today() : today();
    confirmOpen.value = true;
}

async function act(): Promise<void> {
    const date = DATE_FIELDS[confirming.value];

    acting.value = true;
    actionError.value = null;

    try {
        await api.post(path(`invoices/${id.value}/${confirming.value}`), date && actionDate.value ? { [date.field]: actionDate.value } : {});
        confirmOpen.value = false;
        await reload();
    } catch (caught) {
        actionError.value = errorMessage(caught);
    } finally {
        acting.value = false;
    }
}

const CONFIRMS: Record<Action, { title: string; label: string; text: string; destructive?: boolean }> = {
    send: { title: 'Позначити надісланим?', label: 'Надіслано', text: 'Інвойс надіслано клієнту. Він залишиться відкритим до оплати.' },
    pay: { title: 'Позначити оплаченим?', label: 'Оплачено', text: 'Надходження покривають суму інвойсу. Інвойс закриється.' },
    unfreeze: {
        title: 'Повернути в чернетку?',
        label: 'Повернути',
        text: 'Номер збережеться (дата в ньому оновиться, якщо зміните період), знімок і згенерований PDF буде видалено. Інвойс можна буде змінити й зафіксувати знову.',
    },
    cancel: {
        title: 'Скасувати інвойс?',
        label: 'Скасувати інвойс',
        text: 'Скасований інвойс лишається в списку з закресленим номером. Надходження, якщо є, спершу перенесіть на інвойс, що його замінює, або поверніть.',
        destructive: true,
    },
};
</script>

<template>
    <SubScreen title="Інвойс" muted :back="{ name: 'invoices' }">
        <template #action>
            <a
                v-if="invoice?.primary_file_id"
                :href="pdfUrl"
                download
                aria-label="Завантажити PDF"
                class="flex size-11 items-center justify-center text-ink hover:text-ink"
            >
                <Icon name="download" :size="22" />
            </a>
            <button
                v-if="invoice && can('update', 'invoices')"
                type="button"
                aria-label="Інші дії"
                class="flex size-11 items-center justify-center text-ink"
                @click="menuOpen = true"
            >
                <Icon name="more" :size="22" />
            </button>
        </template>

        <LoadState :loading="loading && !invoice" :error="error" @retry="reload">
            <template v-if="invoice && invoice.status !== 'draft'">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <StatusBadge :label="INVOICE_STATUSES[invoice.status].label + statusDate" :tone="INVOICE_STATUSES[invoice.status].tone" />
                        <span v-if="invoice.locked_at" class="flex items-center gap-1 text-12 text-ink-3">
                            <Icon name="lock" :size="14" />
                            Зафіксовано {{ formatDate(kyivDate(invoice.locked_at)) }}
                        </span>
                        <span v-if="invoice.source === 'external'" class="rounded-md border border-line-input px-1.5 py-0.5 text-11 font-semibold text-ink-2">
                            зовнішній
                        </span>
                    </div>
                    <h1 class="num m-0 text-30 font-bold tracking-[-0.02em]" :class="invoice.status === 'cancelled' ? 'line-through' : ''">
                        {{ invoice.number }}
                    </h1>
                    <RouterLink
                        :to="{ name: 'client', params: { id: invoice.client_id } }"
                        class="text-17 text-ink no-underline hover:text-accent"
                    >
                        {{ invoice.client?.name }}
                    </RouterLink>
                </div>

                <InvoiceStepper v-if="invoice.status !== 'cancelled'" :status="invoice.status" />

                <section v-if="invoice.status !== 'cancelled'" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4" aria-label="Отримано">
                    <div class="flex items-baseline justify-between text-15 text-ink-2">
                        <span>Отримано</span>
                        <span class="num text-14">{{ percent }} %</span>
                    </div>
                    <div class="flex items-baseline gap-1.5">
                        <span class="num text-30 font-bold">{{ formatAmount(paid) }}</span>
                        <span class="num text-16 text-ink-3">/ {{ formatMoney(invoice.total, invoice.currency) }}</span>
                    </div>
                    <ProgressBar :percent="percent" :height="8" />
                    <div v-if="compareAmounts(remaining, '0') > 0" class="flex items-baseline justify-between text-14">
                        <span class="text-ink-2">Залишок</span>
                        <span class="num font-semibold">{{ formatMoney(remaining, invoice.currency) }}</span>
                    </div>
                    <RouterLink
                        v-if="isOpen && !covered && can('create', 'payments')"
                        :to="{ name: 'payment-new', query: { invoice: invoice.id } }"
                        class="flex h-13 items-center justify-center gap-2 rounded-button bg-accent text-16 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
                    >
                        <Icon name="plus" :size="20" />
                        Додати надходження
                    </RouterLink>
                </section>

                <section v-if="sortedPayments.length" class="flex flex-col gap-2">
                    <SectionTitle>Надходження</SectionTitle>
                    <ReceiptCard v-for="payment in sortedPayments" :key="payment.id" :payment="payment" />
                </section>

                <section class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4" aria-label="Деталі">
                    <InfoRow label="Період послуг" figure>{{ formatPeriod(invoice.period_start, invoice.period_end) }}</InfoRow>
                    <InfoRow label="Виставлено" figure>{{ formatDate(invoice.issued_at) }}</InfoRow>
                    <InfoRow v-if="invoice.due_at" label="Сплатити до" figure>
                        <span :class="overdue ? 'text-negative' : ''">{{ formatDate(invoice.due_at) }}</span>
                    </InfoRow>
                    <InfoRow v-if="invoice.contract" label="Договір">
                        {{ invoice.contract.name }} · <span class="num">{{ formatDate(invoice.contract.started_at) }}</span>
                    </InfoRow>
                    <InfoRow v-if="invoice.receivingAccount" label="Рахунок отримання">{{ invoice.receivingAccount.name }}</InfoRow>
                </section>

                <section v-if="invoice.items?.length" class="flex flex-col gap-2">
                    <SectionTitle>Позиції</SectionTitle>
                    <InvoiceItemCard v-for="item in invoice.items" :key="item.id" :item="item" />
                    <div v-if="compareAmounts(invoice.adjustment_amount, '0') !== 0" class="flex items-baseline justify-between px-0.5 text-14 text-ink-2">
                        <span>{{ invoice.adjustment_label || 'Коригування' }}</span>
                        <span class="num">{{ formatAmount(invoice.adjustment_amount, { signed: true }) }}</span>
                    </div>
                </section>

                <section v-if="invoice.primaryFile" class="flex flex-col gap-2">
                    <SectionTitle>Документ</SectionTitle>
                    <div class="flex items-center gap-3 rounded-card border border-line bg-surface p-3">
                        <span class="flex h-12 w-10 shrink-0 items-end justify-center rounded-md bg-negative-soft pb-1.5 text-11 font-bold text-negative">PDF</span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="num truncate text-15">{{ invoice.primaryFile.name }}</span>
                            <span class="text-12 text-ink-3">
                                {{ invoice.primaryFile.collection === 'generated' ? 'Згенеровано' : 'Завантажено' }} · основний · {{ fileSize }}
                            </span>
                        </span>
                        <a
                            :href="pdfUrl"
                            download
                            aria-label="Завантажити PDF"
                            class="flex size-11 shrink-0 items-center justify-center rounded-full border border-line-input text-ink hover:text-ink"
                        >
                            <Icon name="download" :size="20" />
                        </a>
                    </div>
                </section>

                <div v-if="can('update', 'invoices')" class="flex flex-col gap-1.5">
                    <TextAreaField
                        v-model="notes"
                        label="Примітка (можна змінювати)"
                        :rows="2"
                        placeholder="Не друкується в інвойсі"
                        :error="notesError ?? undefined"
                        :aside="savingNotes ? 'Зберігаю…' : undefined"
                        @focusout="saveNotes"
                    />
                </div>
                <div v-else-if="invoice.notes" class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">Примітка</span>
                    <p class="m-0 rounded-control border border-line bg-surface px-3.5 py-3 text-15 whitespace-pre-line">{{ invoice.notes }}</p>
                </div>

                <template v-if="isOpen && can('update', 'invoices')">
                    <div class="flex flex-col gap-2">
                        <SecondaryButton v-if="invoice.status === 'ready'" @click="ask('send')">Позначити надісланим</SecondaryButton>
                        <PrimaryButton v-if="covered" @click="ask('pay')">Позначити оплаченим</PrimaryButton>
                        <button
                            v-else
                            type="button"
                            disabled
                            class="flex h-13 w-full items-center justify-center rounded-button bg-muted text-16 font-medium text-ink-3"
                        >
                            Позначити оплаченим
                        </button>
                        <p class="m-0 text-center text-13 leading-normal text-ink-3">
                            <template v-if="!covered">Стане доступним, коли надходження покриють суму.<br></template>
                            <template v-if="invoice.can_unfreeze">Можна повернути в чернетку, поки немає надходжень.</template>
                            <template v-else-if="sortedPayments.length">Повернути в чернетку не можна — вже є надходження.</template>
                        </p>
                    </div>
                    <button type="button" class="flex min-h-11 items-center self-center text-15 font-medium text-negative" @click="ask('cancel')">
                        Скасувати інвойс
                    </button>
                </template>
            </template>
        </LoadState>

        <BottomSheet v-model:open="menuOpen" title="Дії з інвойсом">
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <a v-if="invoice?.primary_file_id" :href="pdfUrl" download class="flex min-h-13 items-center gap-3 text-15 text-ink no-underline hover:text-ink">
                    <Icon name="download" :size="20" /> Завантажити PDF
                </a>
                <button v-if="invoice?.status === 'ready'" type="button" class="flex min-h-13 items-center gap-3 text-left text-15" @click="ask('send')">
                    <Icon name="transfer" :size="20" /> Позначити надісланим
                </button>
                <button v-if="invoice?.can_unfreeze" type="button" class="flex min-h-13 items-center gap-3 text-left text-15" @click="ask('unfreeze')">
                    <Icon name="invoice" :size="20" /> Повернути в чернетку
                </button>
                <button v-if="isOpen" type="button" class="flex min-h-13 items-center gap-3 text-left text-15 text-negative" @click="ask('cancel')">
                    <Icon name="close" :size="20" /> Скасувати інвойс
                </button>
                <p v-if="!isOpen" class="m-0 py-3.5 text-14 text-ink-3">
                    {{ invoice?.status === 'paid' ? 'Оплачений інвойс змінити не можна; лише примітку.' : 'Скасований інвойс змінити не можна; лише примітку.' }}
                </p>
            </div>
        </BottomSheet>

        <ConfirmSheet
            v-model:open="confirmOpen"
            :title="CONFIRMS[confirming].title"
            :confirm-label="CONFIRMS[confirming].label"
            :destructive="CONFIRMS[confirming].destructive"
            :busy="acting"
            :error="actionError"
            @confirm="act"
        >
            <p class="m-0">{{ CONFIRMS[confirming].text }}</p>
            <DateField
                v-if="DATE_FIELDS[confirming]"
                v-model="actionDate"
                :label="DATE_FIELDS[confirming]!.label"
                :max="today()"
                required
            />
        </ConfirmSheet>
    </SubScreen>
</template>
