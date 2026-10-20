<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type {
    Account,
    ApiResponse,
    ChargeType,
    Client,
    Contract,
    Invoice,
    InvoiceDefaults,
    InvoiceDuplicate,
    InvoiceSource,
    Paginated,
    Project,
} from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import FreezeSheet from '@/components/invoice/FreezeSheet.vue';
import InvoiceItemCard from '@/components/invoice/InvoiceItemCard.vue';
import InvoiceItemSheet, { type ItemDraft } from '@/components/invoice/InvoiceItemSheet.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { dateParts, formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, formatMoney, multiplyAmounts, parseAmount, sumAmounts } from '@/utils/money';
import { countOf, DAYS } from '@/utils/plural';

/**
 * Новий інвойс / чернетка (S-InvoiceEdit, S-InvoiceExternal): an invoice made in PryTax from items, or an external
 * PDF with its total typed in. "Зберегти" keeps the draft; "Зафіксувати" saves it and asks before freezing.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings } = useCurrentProfile();

const invoiceId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => invoiceId.value === null);

const existing = ref<Invoice | null>(null);
const clients = ref<Client[]>([]);
const accounts = ref<Account[]>([]);
const contracts = ref<Contract[]>([]);
const projects = ref<Project[]>([]);
const chargeTypes = ref<ChargeType[]>([]);
const defaults = ref<InvoiceDefaults | null>(null);

const form = reactive({
    source: 'generated' as InvoiceSource,
    client_id: null as number | null,
    contract_id: null as number | null,
    receiving_account_id: null as number | null,
    customNumber: false,
    number: '',
    period_start: '',
    period_end: '',
    issued_at: '',
    due_at: '',
    currency: 'USD',
    showAdjustment: false,
    adjustment_amount: '',
    adjustment_label: '',
    total: '',
    notes: '',
    items: [] as ItemDraft[],
});

/** Fields the person set; defaults no longer overwrite them. */
const touched = reactive({ issued_at: false, due_at: false, receiving_account_id: false, currency: false, period: false });

let nextKey = 1;

function endOfMonth(date: string): string {
    const { year, month } = dateParts(date);
    const last = new Date(Date.UTC(year, month, 0)).getUTCDate();

    return `${year}-${String(month).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
}

function dayAfter(date: string): string {
    const { year, month, day } = dateParts(date);

    return new Date(Date.UTC(year, month - 1, day + 1)).toISOString().slice(0, 10);
}

/**
 * Fill the form from a saved draft.
 */
function fill(invoice: Invoice): void {
    Object.assign(form, {
        source: invoice.source,
        client_id: invoice.client_id,
        contract_id: invoice.contract_id,
        receiving_account_id: invoice.receiving_account_id,
        customNumber: invoice.number !== null && invoice.sequence_number === null,
        number: invoice.number ?? '',
        period_start: invoice.period_start,
        period_end: invoice.period_end,
        issued_at: invoice.issued_at ?? '',
        due_at: invoice.due_at ?? '',
        currency: invoice.currency,
        showAdjustment: Number(invoice.adjustment_amount) !== 0 || !!invoice.adjustment_label,
        adjustment_amount: Number(invoice.adjustment_amount) !== 0 ? invoice.adjustment_amount : '',
        adjustment_label: invoice.adjustment_label ?? '',
        total: invoice.source === 'external' ? invoice.total : '',
        notes: invoice.notes ?? '',
        items: (invoice.items ?? []).map((item) => ({
            key: nextKey++,
            project_id: item.project_id,
            charge_type_id: item.charge_type_id,
            description: item.description ?? '',
            unit: item.unit,
            quantity: item.quantity,
            unit_price: item.unit_price ?? '',
        })),
    });
    Object.assign(touched, { issued_at: true, due_at: true, receiving_account_id: true, currency: true, period: true });
}

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [clientList, accountList, invoice, latest] = await Promise.all([
        api.get<Paginated<Client>>(path('clients'), { page: { size: 50 } }, signal),
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 50 } }, signal),
        invoiceId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Invoice>>(path(`invoices/${invoiceId.value}`), {
                include: ['items', 'client', 'receivingAccount', 'primaryFile'],
                append: ['number_preview'],
            }, signal).then(({ data }) => data),
        invoiceId.value === null
            ? api.get<Paginated<Invoice>>(path('invoices'), { page: { size: 1 } }, signal).then(({ data }) => data[0] ?? null)
            : Promise.resolve(null),
    ]);

    clients.value = clientList.data;
    accounts.value = accountList.data;
    existing.value = invoice;

    if (invoice) {
        if (invoice.status !== 'draft') {
            await router.replace({ name: 'invoice', params: { id: invoice.id } });

            return;
        }

        fill(invoice);

        return;
    }

    const wanted = Number(route.query.client) || latest?.client_id;

    form.source = route.query.source === 'external' ? 'external' : 'generated';
    form.client_id = clientList.data.find((client) => client.id === wanted && client.is_active)?.id
        ?? clientList.data.find((client) => client.is_active)?.id
        ?? null;
}, [profileId, invoiceId]);

/**
 * A client's contracts, projects and work types; a new invoice takes its active contract and continues the period
 * after the client's last invoice.
 */
watch(() => form.client_id, async (clientId, previous) => {
    if (clientId === null) {
        return;
    }

    const [contractList, projectList, typeList, last] = await Promise.all([
        api.get<Paginated<Contract>>(path(`clients/${clientId}/contracts`), { include: ['rates'], page: { size: 50 } }),
        api.get<Paginated<Project>>(path(`clients/${clientId}/projects`), { page: { size: 50 } }),
        api.get<Paginated<ChargeType>>(path(`clients/${clientId}/charge-types`), { page: { size: 50 } }),
        isNew.value && !touched.period
            ? api.get<Paginated<Invoice>>(path('invoices'), {
                filter: { client_id: { in: [clientId] }, status: { in: ['ready', 'sent', 'paid'] } },
                sort: '-period_end',
                page: { size: 1 },
            }).then(({ data }) => data[0] ?? null)
            : Promise.resolve(null),
    ]);

    if (clientId !== form.client_id) {
        return;
    }

    contracts.value = contractList.data;
    projects.value = projectList.data.filter((project) => project.is_active || form.items.some((item) => item.project_id === project.id));
    chargeTypes.value = typeList.data.filter((type) => type.is_active || form.items.some((item) => item.charge_type_id === type.id));

    if (previous !== undefined && previous !== null && previous !== clientId) {
        form.items = form.items.map((item) => ({ ...item, project_id: null, charge_type_id: null }));
    }

    if (!contracts.value.some((contract) => contract.id === form.contract_id)) {
        form.contract_id = contracts.value.find((contract) => contract.is_active)?.id ?? null;
    }

    if (isNew.value && !touched.period) {
        const start = last ? dayAfter(last.period_end) : `${today().slice(0, 7)}-01`;

        form.period_start = start;
        form.period_end = endOfMonth(start);
    }
});

/**
 * What the form starts with for the client, contract and period: currency, dates, account, the contract's
 * description and rates, and the number the freeze would give.
 */
watch(() => [form.client_id, form.contract_id, form.period_end, touched.issued_at ? form.issued_at : ''] as const, async ([clientId, contractId, periodEnd, issuedAt]) => {
    if (clientId === null) {
        return;
    }

    try {
        const { data } = await api.get<ApiResponse<InvoiceDefaults>>(path('invoices/defaults'), {
            client_id: clientId,
            contract_id: contractId ?? undefined,
            period_end: periodEnd || undefined,
            issued_at: issuedAt || undefined,
            currency: form.source === 'external' && touched.currency ? form.currency : undefined,
        });

        if (clientId !== form.client_id) {
            return;
        }

        defaults.value = data;

        if (!touched.currency) {
            form.currency = data.currency;
        }

        if (!touched.issued_at) {
            form.issued_at = data.issued_at ?? '';
        }

        if (!touched.due_at) {
            form.due_at = data.due_at ?? '';
        }

        if (!touched.receiving_account_id && data.receiving_account_id !== null) {
            form.receiving_account_id = data.receiving_account_id;
        }
    } catch {
        defaults.value = null;
    }
});

const contract = computed(() => contracts.value.find((item) => item.id === form.contract_id) ?? null);
const rates = computed(() => (contract.value?.currency === form.currency ? contract.value.rates ?? [] : []));
const numberPreview = computed(() => existing.value?.number_preview ?? defaults.value?.number_preview ?? null);

const accountOptions = computed(() => accounts.value
    .filter((account) => account.currency === form.currency
        && ((account.is_active && account.kind !== 'personal') || account.id === form.receiving_account_id))
    .map((account) => ({ value: account.id, label: account.name })));

const account = computed(() => accounts.value.find((item) => item.id === form.receiving_account_id) ?? null);

/** Requisites of the receiving account with numbers masked to their last four digits. */
const requisites = computed(() => {
    const value = account.value;

    if (!value) {
        return [];
    }

    const mask = (text: string): string => `•••• ${text.replace(/\s/g, '').slice(-4)}`;

    return [
        ...(value.iban ? [{ label: 'IBAN', value: mask(value.iban) }] : []),
        ...(value.account_number ? [{ label: 'Account', value: mask(value.account_number) }] : []),
        ...(value.details ?? []).map((detail) => ({ label: detail.label, value: mask(detail.value) })),
    ];
});

const contractHint = computed(() => {
    if (!contract.value) {
        return form.source === 'generated' ? 'Без договору ціну кожної позиції вводите вручну.' : undefined;
    }

    const general = defaults.value?.hourly_rate;
    const days = contract.value.payment_terms_days;

    return [
        contract.value.currency,
        general ? `ставка ${formatAmount(general)} / год` : null,
        days ? `оплата ${countOf(days, DAYS)}` : null,
    ].filter(Boolean).join(' · ');
});

const currencies = computed(() => [...new Set([
    ...accounts.value.filter((item) => item.is_active).map((item) => item.currency),
    form.currency,
])].sort().map((currency) => ({ value: currency, label: currency })));

const itemAmounts = computed(() => form.items.map((item) => {
    const quantity = parseAmount(item.quantity);
    const price = parseAmount(item.unit_price);

    return quantity !== null && price !== null ? multiplyAmounts(quantity, price) : null;
}));
const subtotal = computed(() => sumAmounts(...itemAmounts.value));
const adjustment = computed(() => parseAmount(form.adjustment_amount) ?? '0.00');
const total = computed(() => (form.source === 'external' ? parseAmount(form.total) ?? '0.00' : sumAmounts(subtotal.value, adjustment.value)));

const editing = ref<ItemDraft | null>(null);
const itemOpen = ref(false);

function newItem(): void {
    const chargeTypeId = chargeTypes.value[0]?.id ?? null;
    const rate = defaults.value?.charge_type_rates.find((entry) => entry.charge_type_id === chargeTypeId)?.hourly_rate
        ?? defaults.value?.hourly_rate
        ?? '';

    editing.value = {
        key: nextKey++,
        project_id: projects.value.length === 1 ? projects.value[0]!.id : null,
        charge_type_id: chargeTypeId,
        description: defaults.value?.description ?? '',
        unit: 'hour',
        quantity: '',
        unit_price: rate,
    };
    itemOpen.value = true;
}

function openItem(item: ItemDraft): void {
    editing.value = item;
    itemOpen.value = true;
}

function saveItem(item: ItemDraft): void {
    const index = form.items.findIndex((entry) => entry.key === item.key);

    if (index === -1) {
        form.items.push(item);
    } else {
        form.items[index] = item;
    }
}

function removeItem(key: number): void {
    form.items = form.items.filter((item) => item.key !== key);
}

const editingPosition = computed(() => {
    const index = form.items.findIndex((item) => item.key === editing.value?.key);

    return index === -1 ? form.items.length + 1 : index + 1;
});

/* External PDF: the file to upload, checked for duplicates by its SHA-256 before it's sent. */
const pendingFile = ref<File | null>(null);
const duplicates = ref<InvoiceDuplicate[]>([]);
const checkingFile = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

async function chooseFile(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    pendingFile.value = file;
    duplicates.value = [];

    if (!form.number) {
        form.number = file.name.replace(/\.pdf$/i, '');
    }

    if (!window.crypto?.subtle) {
        return;
    }

    checkingFile.value = true;

    try {
        const digest = await window.crypto.subtle.digest('SHA-256', await file.arrayBuffer());
        const sha256 = [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        const { data } = await api.get<ApiResponse<InvoiceDuplicate[]>>(path('invoices/duplicates'), { sha256 });

        duplicates.value = data.filter((duplicate) => duplicate.invoice_id !== invoiceId.value);
    } catch {
        duplicates.value = [];
    } finally {
        checkingFile.value = false;
    }
}

function dropFile(): void {
    pendingFile.value = null;
    duplicates.value = [];

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

const fileName = computed(() => pendingFile.value?.name ?? existing.value?.primaryFile?.name ?? null);

/* Saving, freezing and deleting */
const saving = ref(false);
const saveError = ref<unknown>(null);
const savedAt = ref<string | null>(null);

function payload(): Record<string, unknown> {
    const external = form.source === 'external';

    return {
        source: form.source,
        client_id: form.client_id,
        contract_id: form.contract_id,
        receiving_account_id: form.receiving_account_id,
        period_start: form.period_start,
        period_end: form.period_end,
        issued_at: form.issued_at || null,
        due_at: form.due_at || null,
        currency: form.currency,
        adjustment_amount: form.showAdjustment ? parseAmount(form.adjustment_amount) ?? (form.adjustment_amount || '0') : '0',
        adjustment_label: form.showAdjustment ? form.adjustment_label.trim() || null : null,
        notes: form.notes.trim() || null,
        ...(external || form.customNumber
            ? { number: form.number.trim() || null }
            // Back to the generator: clear a number typed before
            : existing.value?.number && existing.value.sequence_number === null ? { number: null } : {}),
        ...(external ? { total: parseAmount(form.total) ?? form.total } : {}),
        items: form.items.map((item) => ({
            project_id: item.project_id,
            charge_type_id: item.charge_type_id,
            description: item.description || null,
            unit: item.unit,
            quantity: parseAmount(item.quantity) ?? item.quantity,
            unit_price: parseAmount(item.unit_price) ?? (item.unit_price || null),
        })),
    };
}

/**
 * Save the draft (create or update it), then upload a chosen PDF as its primary document.
 */
async function save(): Promise<Invoice | null> {
    saving.value = true;
    saveError.value = null;

    try {
        const { data } = existing.value === null
            ? await api.post<ApiResponse<Invoice>>(path('invoices'), payload())
            : await api.patch<ApiResponse<Invoice>>(path(`invoices/${existing.value.id}`), payload(), {
                'If-Unmodified-Since': existing.value.updated_at,
            });

        if (pendingFile.value) {
            const body = new FormData();

            body.append('file', pendingFile.value);
            body.append('primary', '1');
            await api.post(path(`invoices/${data.id}/files`), body);
            pendingFile.value = null;
        }

        const { data: saved } = await api.get<ApiResponse<Invoice>>(path(`invoices/${data.id}`), {
            include: ['items', 'client', 'receivingAccount', 'primaryFile'],
            append: ['number_preview'],
        });

        existing.value = saved;
        savedAt.value = new Date().toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });

        if (isNew.value) {
            await router.replace({ name: 'invoice-edit', params: { id: saved.id } });
        }

        return saved;
    } catch (caught) {
        saveError.value = caught;

        return null;
    } finally {
        saving.value = false;
    }
}

const freezeOpen = ref(false);
const freezing = ref(false);
const freezeError = ref<string | null>(null);

async function askFreeze(): Promise<void> {
    freezeError.value = null;

    if (await save()) {
        freezeOpen.value = true;
    }
}

async function freeze(): Promise<void> {
    if (!existing.value) {
        return;
    }

    freezing.value = true;
    freezeError.value = null;

    try {
        await api.post(path(`invoices/${existing.value.id}/freeze`), {});
        freezeOpen.value = false;
        await router.replace({ name: 'invoice', params: { id: existing.value.id } });
    } catch (caught) {
        freezeError.value = errorMessage(caught);
    } finally {
        freezing.value = false;
    }
}

const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

async function remove(): Promise<void> {
    if (!existing.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = null;

    try {
        await api.delete(path(`invoices/${existing.value.id}`));
        deleteOpen.value = false;
        await router.replace({ name: 'invoices' });
    } catch (caught) {
        deleteError.value = errorMessage(caught);
    } finally {
        deleting.value = false;
    }
}

const generalError = computed(() => {
    if (!saveError.value) {
        return null;
    }

    const fields = ['client_id', 'contract_id', 'receiving_account_id', 'number', 'period_start', 'period_end', 'issued_at', 'due_at', 'total', 'adjustment_amount'];
    const shown = fields.some((field) => fieldError(saveError.value, field));

    return shown ? 'Перевірте поля, позначені червоним.' : errorMessage(saveError.value);
});

const itemErrors = computed(() => form.items.map((_item, index) => fieldError(saveError.value, `items.${index}.quantity`)
    ?? fieldError(saveError.value, `items.${index}.unit_price`)
    ?? fieldError(saveError.value, `items.${index}.description`)));

const title = computed(() => (isNew.value ? (form.source === 'external' ? 'Зовнішній інвойс' : 'Новий інвойс') : 'Чернетка інвойсу'));
</script>

<template>
    <SubScreen :title="title" :back="{ name: 'invoices' }">
        <template #action>
            <StatusBadge label="Чернетка" class="mr-3" />
        </template>

        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <SegmentedControl
                v-if="isNew"
                v-model="form.source"
                label="Джерело інвойсу"
                :options="[
                    { value: 'generated', label: 'Створити в PryTax' },
                    { value: 'external', label: 'Зовнішній PDF' },
                ]"
            />

            <!-- Generated: the number the freeze will give -->
            <section v-if="form.source === 'generated'" class="flex flex-col gap-2.5 rounded-card border border-line bg-surface p-4">
                <div class="flex items-center justify-between">
                    <span class="text-13 font-medium text-ink-2">Номер</span>
                    <button
                        type="button"
                        class="min-h-9 text-14 font-medium text-accent"
                        @click="form.customNumber = !form.customNumber"
                    >
                        {{ form.customNumber ? 'Автоматичний' : 'Ввести власний' }}
                    </button>
                </div>
                <TextField
                    v-if="form.customNumber"
                    v-model="form.number"
                    label="Власний номер"
                    class="num"
                    :error="fieldError(saveError, 'number')"
                />
                <template v-else>
                    <div class="flex items-baseline gap-2.5">
                        <span class="num text-22 font-semibold">{{ numberPreview ?? '—' }}</span>
                        <span class="text-12 text-ink-3">попередній перегляд</span>
                    </div>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">
                        Номер буде присвоєно під час фіксації: дата кінця періоду + лічильник
                        {{ settings?.invoice.numbering.scope === 'per_client' ? 'клієнта' : 'ФОП' }}.
                    </p>
                </template>
            </section>

            <!-- External: the uploaded PDF -->
            <template v-else>
                <p class="m-0 text-15 leading-normal text-ink-2">
                    Для історичних інвойсів або коли клієнт виставляє інвойс сам. Завантажений PDF стає основним
                    документом, суму вводите вручну.
                </p>
                <div v-if="fileName" class="flex items-center gap-3 rounded-card border border-line bg-surface p-3">
                    <span class="flex h-12 w-10 shrink-0 items-end justify-center rounded-md bg-negative-soft pb-1.5 text-11 font-bold text-negative">PDF</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate text-15">{{ fileName }}</span>
                        <span v-if="checkingFile" class="text-12 text-ink-3">Перевіряю на дублікати…</span>
                        <span v-else-if="duplicates.length" class="text-12 text-warning-text">
                            Такий самий файл уже є в інвойсі
                            <template v-for="(duplicate, index) in duplicates" :key="duplicate.invoice_id">
                                {{ index ? ', ' : '' }}<RouterLink :to="{ name: 'invoice', params: { id: duplicate.invoice_id } }" class="num">{{ duplicate.number ?? 'чернетка' }}</RouterLink>
                            </template>
                        </span>
                        <span v-else-if="pendingFile" class="text-12 text-positive">Готово до завантаження · дублікатів не знайдено</span>
                        <span v-else class="text-12 text-positive">Завантажено · основний документ</span>
                    </span>
                    <button v-if="pendingFile" type="button" aria-label="Прибрати файл" class="flex size-11 items-center justify-center text-ink-2" @click="dropFile">
                        <Icon name="close" :size="20" />
                    </button>
                </div>
                <label class="flex h-12 cursor-pointer items-center justify-center gap-2 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent">
                    <Icon name="upload" :size="20" />
                    {{ fileName ? 'Замінити PDF' : 'Обрати PDF' }}
                    <input ref="fileInput" type="file" accept="application/pdf,.pdf" class="sr-only" @change="chooseFile">
                </label>
                <TextField
                    v-model="form.number"
                    label="Номер інвойсу"
                    hint="Власний номер не змінюється генератором"
                    :error="fieldError(saveError, 'number')"
                />
            </template>

            <section class="flex flex-col gap-3">
                <SectionTitle>{{ form.source === 'generated' ? 'Клієнт і договір' : 'Клієнт' }}</SectionTitle>
                <SelectField
                    v-model="form.client_id"
                    label="Клієнт"
                    placeholder="Оберіть клієнта"
                    :options="clients.filter((client) => client.is_active || client.id === form.client_id).map((client) => ({ value: client.id, label: client.name }))"
                    :error="fieldError(saveError, 'client_id')"
                />
                <SelectField
                    v-if="form.source === 'generated'"
                    :model-value="form.contract_id ?? 0"
                    label="Договір"
                    :options="[
                        { value: 0, label: 'Без договору' },
                        ...contracts.map((item) => ({ value: item.id, label: `${item.name} · ${formatDate(item.started_at)}` })),
                    ]"
                    :hint="contractHint"
                    :error="fieldError(saveError, 'contract_id')"
                    @update:model-value="form.contract_id = $event || null"
                />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Період і дати</SectionTitle>
                <div class="grid grid-cols-2 gap-2.5">
                    <DateField
                        v-model="form.period_start"
                        label="Період з"
                        :error="fieldError(saveError, 'period_start')"
                        @update:model-value="touched.period = true"
                    />
                    <DateField
                        v-model="form.period_end"
                        label="по"
                        :min="form.period_start || undefined"
                        :error="fieldError(saveError, 'period_end')"
                        @update:model-value="touched.period = true"
                    />
                    <DateField
                        v-model="form.issued_at"
                        :label="form.source === 'generated' ? 'Дата виставлення' : 'Виставлено'"
                        :error="fieldError(saveError, 'issued_at')"
                        @update:model-value="touched.issued_at = true"
                    />
                    <DateField
                        v-if="form.source === 'generated'"
                        v-model="form.due_at"
                        label="Сплатити до"
                        :error="fieldError(saveError, 'due_at')"
                        @update:model-value="touched.due_at = true"
                    />
                    <SelectField
                        v-else
                        v-model="form.currency"
                        label="Валюта"
                        :options="currencies"
                        @update:model-value="touched.currency = true"
                    />
                </div>
            </section>

            <TextField
                v-if="form.source === 'external'"
                v-model="form.total"
                label="Сума інвойсу"
                numeric
                class="text-18 font-bold"
                :error="fieldError(saveError, 'total')"
            />

            <section class="flex flex-col gap-3">
                <SectionTitle v-if="form.source === 'generated'">Отримання коштів</SectionTitle>
                <SelectField
                    v-model="form.receiving_account_id"
                    :label="form.source === 'generated' ? 'Рахунок для оплати' : 'Рахунок отримання'"
                    placeholder="Оберіть рахунок"
                    :options="accountOptions"
                    :hint="`Лише активні рахунки в ${form.currency}`"
                    :error="fieldError(saveError, 'receiving_account_id')"
                    @update:model-value="touched.receiving_account_id = true"
                />
                <div
                    v-if="form.source === 'generated' && account"
                    class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2"
                >
                    <span v-if="account.holder_name">Отримувач: <span class="text-ink">{{ account.holder_name }}</span></span>
                    <span v-if="requisites.length">
                        <template v-for="(requisite, index) in requisites" :key="requisite.label">
                            {{ index ? ' · ' : '' }}{{ requisite.label }} <span class="num text-ink">{{ requisite.value }}</span>
                        </template>
                    </span>
                    <span>Реквізити буде надруковано в інвойсі</span>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>
                    Позиції<template v-if="form.source === 'external'"> (необов'язково)</template>
                    <template #aside><span class="num">{{ form.currency }}</span></template>
                </SectionTitle>
                <div v-for="(item, index) in form.items" :key="item.key" class="flex flex-col gap-1">
                    <InvoiceItemCard
                        :item="{
                            ...item,
                            project_code: projects.find((project) => project.id === item.project_id)?.code ?? null,
                            charge_type: chargeTypes.find((type) => type.id === item.charge_type_id)?.name ?? null,
                            amount: itemAmounts[index] ?? null,
                        }"
                        editable
                        @open="openItem(item)"
                    />
                    <p v-if="itemErrors[index]" class="m-0 px-1 text-12 text-negative">{{ itemErrors[index] }}</p>
                </div>
                <button
                    type="button"
                    class="flex h-12 items-center justify-center gap-2 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent"
                    :disabled="form.client_id === null"
                    @click="newItem"
                >
                    <Icon name="plus" :size="20" />
                    Додати позицію
                </button>
                <p v-if="fieldError(saveError, 'items')" class="m-0 text-12 text-negative">{{ fieldError(saveError, 'items') }}</p>

                <template v-if="form.source === 'generated'">
                    <button
                        v-if="!form.showAdjustment"
                        type="button"
                        class="flex min-h-11 items-center self-start text-14 font-medium text-accent"
                        @click="form.showAdjustment = true"
                    >
                        + Знижка або коригування
                    </button>
                    <div v-else class="grid grid-cols-[2fr_1fr] gap-2.5">
                        <TextField v-model="form.adjustment_label" label="Коригування" placeholder="Знижка" />
                        <TextField
                            v-model="form.adjustment_amount"
                            label="Сума, ±"
                            numeric
                            placeholder="-100.00"
                            :error="fieldError(saveError, 'adjustment_amount')"
                        />
                    </div>
                </template>
            </section>

            <section v-if="form.source === 'generated'" class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4" aria-label="Підсумок">
                <div class="flex justify-between text-14 text-ink-2">
                    <span>Проміжна сума</span>
                    <span class="num">{{ formatAmount(subtotal) }}</span>
                </div>
                <div class="flex justify-between text-14 text-ink-2">
                    <span>{{ form.adjustment_label || 'Коригування' }}</span>
                    <span class="num">{{ formatAmount(adjustment) }}</span>
                </div>
                <div class="flex items-baseline justify-between border-t border-divider pt-2.5">
                    <span class="text-16 font-semibold">Разом</span>
                    <span class="num text-22 font-semibold">{{ formatMoney(total, form.currency) }}</span>
                </div>
            </section>

            <TextAreaField v-model="form.notes" label="Внутрішня примітка" placeholder="Не друкується в інвойсі" />

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
            <p v-else-if="savedAt && !saving" class="m-0 text-13 text-ink-3" role="status">Чернетку збережено о {{ savedAt }}.</p>

            <button
                v-if="existing && can('delete', 'invoices')"
                type="button"
                class="flex min-h-11 items-center self-center text-15 font-medium text-negative"
                @click="deleteOpen = true"
            >
                Видалити чернетку
            </button>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'invoices')" #footer>
            <div class="grid grid-cols-[1fr_1.4fr] gap-2.5">
                <SecondaryButton :busy="saving && !freezeOpen" :disabled="loading || form.client_id === null" @click="save">Зберегти</SecondaryButton>
                <PrimaryButton :disabled="loading || saving || form.client_id === null" @click="askFreeze">
                    <Icon name="lock" :size="20" />
                    Зафіксувати
                </PrimaryButton>
            </div>
        </template>

        <InvoiceItemSheet
            v-model:open="itemOpen"
            :item="editing"
            :position="editingPosition"
            :currency="form.currency"
            :projects="projects"
            :charge-types="chargeTypes"
            :rates="rates"
            :rate-date="form.period_end || today()"
            :template="defaults?.description ?? null"
            :removable="form.items.some((item) => item.key === editing?.key)"
            @save="saveItem"
            @remove="removeItem"
        />

        <FreezeSheet
            v-if="existing"
            v-model:open="freezeOpen"
            :invoice="existing"
            :busy="freezing"
            :error="freezeError"
            :counter-scope="settings?.invoice.numbering.scope"
            @confirm="freeze"
        />

        <ConfirmSheet
            v-model:open="deleteOpen"
            title="Видалити чернетку?"
            confirm-label="Видалити"
            destructive
            :busy="deleting"
            :error="deleteError"
            @confirm="remove"
        >
            <p class="m-0">Чернетку буде видалено. Її номер, якщо вже показувався, перейде до наступного інвойсу.</p>
        </ConfirmSheet>
    </SubScreen>
</template>
