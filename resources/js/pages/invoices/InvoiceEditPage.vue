<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type {
    Account,
    ApiResponse,
    ChargeType,
    Client,
    Contract,
    Invoice,
    InvoiceDefaults,
    InvoiceDocumentSettings,
    InvoiceLocale,
    InvoiceTexts,
    InvoiceDuplicate,
    InvoiceSource,
    Paginated,
    Profile,
    Project,
    StoredFile,
} from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import DateField from '@/components/form/DateField.vue';
import DateRangeField from '@/components/form/DateRangeField.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import FreezeSheet from '@/components/invoice/FreezeSheet.vue';
import InvoiceDocumentLook from '@/components/invoice/InvoiceDocumentLook.vue';
import InvoiceDocumentTexts from '@/components/invoice/InvoiceDocumentTexts.vue';
import InvoiceItemCard, { type ItemView } from '@/components/invoice/InvoiceItemCard.vue';
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
import Translated from '@/components/ui/Translated.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useInvoiceDocument } from '@/composables/useInvoiceDocument';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, t } from '@/i18n';
import { dateParts, formatDate, formatPeriod, monthName, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { type PurposeSample, purposeValues, purposeWarnings, renderPurpose } from '@/utils/invoiceDocument';
import { DEFAULT_INVOICE_STYLE, styleName } from '@/utils/invoiceStyles';
import { formatAmount, formatMoney, multiplyAmounts, parseAmount, sumAmounts } from '@/utils/money';

/**
 * Новий інвойс / чернетка (M-InvoiceEdit, M-InvoiceExternal, design.md §16.5): an invoice made in PryTax, or an
 * external PDF with its total typed in. One made in PryTax shows the number its freeze will give, then accordion
 * groups — Клієнт і договір, Період і дати, Отримання коштів, Документ (its style, language, contract section, payment
 * purpose, act note and the two notes, each the client's unless the invoice has its own) and Внутрішня примітка —
 * and its items, which aren't a group. The draft is saved after each group and each item; «Зафіксувати» asks before
 * freezing. An external one stays one form with «Зберегти». Leaving an open group with changes asks first.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings, profile } = useCurrentProfile();

const invoiceId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => invoiceId.value === null);
const accordion = provideAccordion();

const existing = ref<Invoice | null>(null);
const clients = ref<Client[]>([]);
const accounts = ref<Account[]>([]);
const contracts = ref<Contract[]>([]);
const projects = ref<Project[]>([]);
const chargeTypes = ref<ChargeType[]>([]);
const defaults = ref<InvoiceDefaults | null>(null);
/** The built-in texts of invoices, which the document settings show in the chosen language. */
const texts = ref<Record<InvoiceLocale, InvoiceTexts> | null>(null);

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
    document: {
        locale: null,
        payment_purpose_template: null,
        act_note_enabled: null,
        act_note: null,
        show_contract: null,
        style: null,
        payment_note_enabled: null,
        payment_note: null,
        other_note_enabled: null,
        other_note: null,
    } as InvoiceDocumentSettings,
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
        document: {
            locale: invoice.locale,
            payment_purpose_template: invoice.payment_purpose_template,
            act_note_enabled: invoice.act_note_enabled,
            act_note: invoice.act_note,
            show_contract: invoice.show_contract,
            style: invoice.style,
            payment_note_enabled: invoice.payment_note_enabled,
            payment_note: invoice.payment_note,
            other_note_enabled: invoice.other_note_enabled,
            other_note: invoice.other_note,
        },
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

/** The person changed the form since it was loaded or saved. */
const dirty = ref(false);

/**
 * A saved draft with what the editor shows of it.
 */
async function fetchInvoice(id: number, signal?: AbortSignal): Promise<Invoice> {
    const { data } = await api.get<ApiResponse<Invoice>>(path(`invoices/${id}`), {
        include: ['items', 'client', 'receivingAccount', 'primaryFile'],
        append: ['number_preview'],
    }, signal);

    return data;
}

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [clientList, accountList, invoice, latest, owner] = await Promise.all([
        api.all<Client>(path('clients'), {}, signal),
        api.all<Account>(path('accounts'), {}, signal),
        invoiceId.value === null ? Promise.resolve(null) : fetchInvoice(invoiceId.value, signal),
        invoiceId.value === null
            ? api.get<Paginated<Invoice>>(path('invoices'), { page: { size: 1 } }, signal).then(({ data }) => data[0] ?? null)
            : Promise.resolve(null),
        api.get<ApiResponse<Profile>>(`profiles/${profileId.value}`, { append: ['invoice_texts'] }, signal),
    ]);

    texts.value = owner.data.invoice_texts ?? null;
    clients.value = clientList;
    accounts.value = accountList;
    existing.value = invoice;
    // A PDF, which failed to upload with the first save, is still waiting
    dirty.value = pendingFile.value !== null;

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
    form.client_id = clientList.find((client) => client.id === wanted && client.is_active)?.id
        ?? clientList.find((client) => client.is_active)?.id
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
        api.all<Contract>(path(`clients/${clientId}/contracts`), { include: ['rates'] }),
        api.all<Project>(path(`clients/${clientId}/projects`)),
        api.all<ChargeType>(path(`clients/${clientId}/charge-types`)),
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

    contracts.value = contractList;
    projects.value = projectList.filter((project) => project.is_active || form.items.some((item) => item.project_id === project.id));
    chargeTypes.value = typeList.filter((type) => type.is_active || form.items.some((item) => item.charge_type_id === type.id));

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

/** Whether the account is one of the profile's, in the currency. */
const isAccountIn = (accountId: number | null, currency: string): boolean => accounts.value
    .some((item) => item.id === accountId && item.currency === currency);

/* An account left from another currency (a contract or a typed currency changed it) is chosen again */
watch(() => form.currency, (currency) => {
    if (form.receiving_account_id !== null && accounts.value.length && !isAccountIn(form.receiving_account_id, currency)) {
        form.receiving_account_id = null;
        touched.receiving_account_id = false;
    }
});

/** Counts the requests for defaults, so only the answer to the latest one fills the form. */
let defaultsRun = 0;

/**
 * The form's contract, once it is one of its client's loaded contracts; `undefined` while a changed client still
 * holds the previous client's contract, which the loaded contracts then replace.
 */
const contractOfClient = computed(() => (form.contract_id === null
    || contracts.value.some((item) => item.id === form.contract_id && item.client_id === form.client_id)
    ? form.contract_id
    : undefined));

/**
 * What the form starts with for the client, contract and period: currency, dates, account, the contract's
 * description and rates, and the number the freeze would give. An external invoice has no contract: its currency is
 * the client's, or the one typed.
 */
watch(() => [
    form.client_id,
    form.source === 'generated' ? contractOfClient.value : null,
    form.period_end,
    touched.issued_at ? form.issued_at : '',
    form.source === 'external' && touched.currency ? form.currency : '',
] as const, async ([clientId, contractId, periodEnd, issuedAt, currency]) => {
    if (clientId === null || contractId === undefined) {
        return;
    }

    const run = ++defaultsRun;

    try {
        const { data } = await api.get<ApiResponse<InvoiceDefaults>>(path('invoices/defaults'), {
            client_id: clientId,
            contract_id: contractId ?? undefined,
            period_end: periodEnd || undefined,
            issued_at: issuedAt || undefined,
            currency: currency || undefined,
        });

        if (run !== defaultsRun) {
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

        if ((!touched.receiving_account_id || !isAccountIn(form.receiving_account_id, form.currency))
            && isAccountIn(data.receiving_account_id, form.currency)) {
            form.receiving_account_id = data.receiving_account_id;
            touched.receiving_account_id = false;
        }
    } catch {
        if (run === defaultsRun) {
            defaults.value = null;
        }
    }
});

/* A PDF chosen for an external invoice isn't uploaded into one made in PryTax */
watch(() => form.source, (source) => {
    if (source === 'generated') {
        dropFile();
    }
});

const contract = computed(() => contracts.value.find((item) => item.id === form.contract_id) ?? null);

/* The document: its settings over the client's, and the payment purpose, which the form's invoice would print */

/** The invoice of the form, on which the payment purpose is previewed. */
const purposeSample = computed<PurposeSample>(() => ({
    number: (form.customNumber ? form.number.trim() : numberPreview.value) || null,
    issuedAt: form.issued_at || null,
    periodStart: form.period_start || null,
    periodEnd: form.period_end || null,
    contract: contract.value,
    client: clients.value.find((client) => client.id === form.client_id) ?? null,
    profile: profile.value,
}));

/** The style and language the invoice prints in. */
const documentStyle = computed(() => form.document.style ?? defaults.value?.effective_settings.style.value ?? DEFAULT_INVOICE_STYLE);
const documentLocale = computed(() => form.document.locale ?? defaults.value?.effective_settings.locale.value ?? 'en');

/** What the document settings components know besides the invoice's own values. */
const documentContext = computed(() => ({
    inherited: defaults.value?.effective_settings ?? null,
    texts: texts.value,
    sample: purposeSample.value,
    currency: form.currency,
    level: 'invoice' as const,
}));
const documentModel = computed({
    get: () => form.document,
    set: (value: InvoiceDocumentSettings) => {
        form.document = value;
    },
});
const shownDocument = useInvoiceDocument(documentModel, () => documentContext.value);

/** What the invoice's texts print, in short: «без примітки «акт» · примітки щодо оплати й інша». */
const textsSummary = computed(() => [
    shownDocument.actEnabled.value ? t('invoices.document.withActNote') : t('invoices.document.withoutActNote'),
    ...(['payment_note', 'other_note'] as const)
        .filter((name) => shownDocument.noteEnabled(name))
        .map((name) => (name === 'payment_note' ? t('invoices.document.paymentNote') : t('invoices.document.otherNote'))),
].join(' · '));

/** Whether the payment purpose, which the invoice would print, can't go through SWIFT as it is. */
const purposeWarned = computed(() => {
    const inherited = defaults.value?.effective_settings;
    const locale = form.document.locale ?? inherited?.locale.value ?? 'en';
    const template = form.document.payment_purpose_template
        || (inherited?.payment_purpose_template.source === 'default' && texts.value ? texts.value[locale].payment_purpose : inherited?.payment_purpose_template.value);

    return template ? purposeWarnings(renderPurpose(template, purposeValues(purposeSample.value, locale, texts.value?.[locale] ?? null)), form.currency).length > 0 : false;
});
const rates = computed(() => (contract.value?.currency === form.currency ? contract.value.rates ?? [] : []));
const numbering = computed(() => settings.value?.invoice.numbering ?? null);

/**
 * The number the freeze would give: the saved draft's while its client and dates are as saved; a draft, which was
 * frozen before, keeps its counter's number while the counter is the same (only the client's abbreviation and the
 * date in it change); else the next number for the form's client and dates.
 */
const numberPreview = computed(() => {
    const saved = existing.value;

    if (saved && saved.client_id === form.client_id && saved.period_end === form.period_end && saved.issued_at === form.issued_at) {
        return saved.number_preview ?? null;
    }

    if (saved?.sequence_number != null && numbering.value) {
        const date = numbering.value.date_source === 'issued_at' ? form.issued_at : form.period_end;
        const scope = (numbering.value.scope === 'global' ? 'global' : `client:${form.client_id}`)
            + (numbering.value.reset === 'yearly' && date ? `:${date.slice(0, 4)}` : '');
        const abbreviation = clients.value.find((client) => client.id === form.client_id)?.abbreviation;
        const prefix = numbering.value.prefix === 'client_abbreviation' && abbreviation ? `${abbreviation}-` : '';

        if (date && scope === saved.sequence_scope_key) {
            return prefix + (numbering.value.strategy === 'sequence' ? String(saved.sequence_number) : `${date}-${saved.sequence_number}`);
        }
    }

    return defaults.value?.number_preview ?? saved?.number_preview ?? null;
});

const numberHint = computed(() => {
    const parts = [
        numbering.value?.prefix === 'none' ? null : t('invoices.numberParts.abbreviation'),
        numbering.value?.strategy === 'sequence' ? null : (numbering.value?.date_source === 'issued_at' ? t('invoices.numberParts.issueDate') : t('invoices.numberParts.periodEnd')),
        numbering.value?.scope === 'global' ? t('invoices.numberParts.fopCounter') : t('invoices.numberParts.clientCounter'),
    ];

    const named = parts.filter((part): part is string => part !== null);
    const listed = named.length > 1
        ? `${named.slice(0, -1).join(', ')} ${t('invoices.numberParts.and')} ${named[named.length - 1]}`
        : named.join('');

    return t('invoices.editor.numberOnFreeze', { parts: listed });
});

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
        return form.source === 'generated' ? t('invoices.editor.noContractNote') : undefined;
    }

    const general = defaults.value?.hourly_rate;
    const days = contract.value.payment_terms_days;

    return [
        contract.value.currency,
        general ? t('invoices.editor.ratePerHour', { rate: formatAmount(general) }) : null,
        days ? t('invoices.editor.paymentWithin', { days: t('core.counts.days', { count: days }) }) : null,
    ].filter(Boolean).join(' · ');
});

const sourceOptions = computed<{ value: InvoiceSource; label: string }[]>(() => [
    { value: 'generated', label: t('invoices.editor.createPrytax') },
    { value: 'external', label: t('invoices.editor.externalPdf') },
]);

const contractOptions = computed(() => [
    { value: 0, label: t('invoices.editor.noContract') },
    ...contracts.value.map((item) => ({ value: item.id, label: `${item.name} · ${formatDate(item.started_at)}` })),
]);

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

/** An item as its card shows it: the typed quantity and price as amounts, with its project's code and work type. */
function itemView(item: ItemDraft, index: number): ItemView {
    return {
        ...item,
        quantity: parseAmount(item.quantity) ?? item.quantity,
        unit_price: parseAmount(item.unit_price) ?? (item.unit_price || null),
        project_code: projects.value.find((project) => project.id === item.project_id)?.code ?? null,
        charge_type: chargeTypes.value.find((type) => type.id === item.charge_type_id)?.name ?? null,
        amount: itemAmounts.value[index] ?? null,
    };
}
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

    dirty.value = true;

    if (index === -1) {
        form.items.push(item);
    } else {
        form.items[index] = item;
    }

    keepDraft();
}

function removeItem(key: number): void {
    dirty.value = true;
    form.items = form.items.filter((item) => item.key !== key);
    keepDraft();
}

/**
 * A stored draft made in PryTax is saved after each change of its items or number; a new one keeps them in the form
 * until «Зберегти чернетку», and an external one waits for «Зберегти».
 */
function keepDraft(): void {
    if (form.source !== 'generated') {
        return;
    }

    if (existing.value) {
        void save();
    } else {
        pending.value = true;
    }
}

const editingPosition = computed(() => {
    const index = form.items.findIndex((item) => item.key === editing.value?.key);

    return index === -1 ? form.items.length + 1 : index + 1;
});

/* External PDF: the file to upload, checked for duplicates by its SHA-256 before it's sent (where the browser can
 * hash it: a page served over HTTPS or from localhost), and by the upload itself. */
const pendingFile = ref<File | null>(null);
const duplicates = ref<Pick<InvoiceDuplicate, 'invoice_id' | 'number'>[]>([]);
const checkingFile = ref(false);
const isChecked = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

async function chooseFile(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    pendingFile.value = file;
    duplicates.value = [];
    isChecked.value = false;
    dirty.value = true;

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

        if (pendingFile.value === file) {
            duplicates.value = data.filter((duplicate) => duplicate.invoice_id !== existing.value?.id);
            isChecked.value = true;
        }
    } catch {
        duplicates.value = [];
    } finally {
        checkingFile.value = false;
    }
}

function dropFile(): void {
    pendingFile.value = null;
    duplicates.value = [];
    isChecked.value = false;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

const fileName = computed(() => pendingFile.value?.name ?? existing.value?.primaryFile?.name ?? null);

/* The PDF uploaded before: it stays through a return to the draft, and goes only when replaced or deleted here */
const fileDeleteOpen = ref(false);
const deletingFile = ref(false);
const fileDeleteError = ref<string | null>(null);

async function deleteUploadedFile(): Promise<void> {
    const invoice = existing.value;

    if (!invoice?.primary_file_id) {
        return;
    }

    deletingFile.value = true;
    fileDeleteError.value = null;

    try {
        await api.delete(path(`invoices/${invoice.id}/files/${invoice.primary_file_id}`));
        existing.value = await fetchInvoice(invoice.id);
        duplicates.value = [];
        fileDeleteOpen.value = false;
    } catch (caught) {
        fileDeleteError.value = errorMessage(caught);
    } finally {
        deletingFile.value = false;
    }
}

/* Saving, freezing and deleting */
const saving = ref(false);
const saveError = ref<unknown>(null);
const savedAt = ref<string | null>(null);

/** Changes of a new invoice made in PryTax, which only «Зберегти чернетку» stores. */
const pending = ref(false);

/** What a new draft needs before it can be stored: its client, period and the account it's paid to. */
const missing = computed(() => [
    form.client_id === null ? t('invoices.editor.needs.client') : null,
    !form.period_start || !form.period_end ? t('invoices.editor.needs.period') : null,
    form.receiving_account_id === null ? t('invoices.editor.needs.account') : null,
].filter((item): item is string => item !== null));

/** The footer's line: what a new invoice still needs, that it isn't stored yet, or when the draft was saved. */
const draftState = computed(() => {
    if (existing.value) {
        return `${savedAt.value ? t('invoices.editor.draftSaved', { time: savedAt.value }) : t('invoices.editor.draftStored')} ${t('invoices.editor.savesPerGroup')}`;
    }

    return missing.value.length > 0 ? t('invoices.editor.toSave', { missing: missing.value.join(', ') }) : t('invoices.editor.notSavedYet');
});

function payload(): Record<string, unknown> {
    const external = form.source === 'external';

    return {
        source: form.source,
        client_id: form.client_id,
        // An external invoice has neither a contract nor a due date on its form, so none is made up for it
        contract_id: external ? null : form.contract_id,
        receiving_account_id: form.receiving_account_id,
        period_start: form.period_start,
        period_end: form.period_end,
        issued_at: form.issued_at || null,
        due_at: external ? null : form.due_at || null,
        currency: form.currency,
        adjustment_amount: form.showAdjustment ? parseAmount(form.adjustment_amount) ?? (form.adjustment_amount || '0') : '0',
        adjustment_label: form.showAdjustment ? form.adjustment_label.trim() || null : null,
        notes: form.notes.trim() || null,
        locale: form.document.locale,
        payment_purpose_template: form.document.payment_purpose_template?.trim() || null,
        act_note_enabled: form.document.act_note_enabled,
        act_note: form.document.act_note?.trim() || null,
        show_contract: form.document.show_contract,
        style: form.document.style,
        payment_note_enabled: form.document.payment_note_enabled,
        payment_note: form.document.payment_note?.trim() || null,
        other_note_enabled: form.document.other_note_enabled,
        other_note: form.document.other_note?.trim() || null,
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
 * Upload the chosen PDF as the external draft's primary document; the duplicates the upload finds are shown.
 */
async function upload(id: number, file: File): Promise<void> {
    const body = new FormData();

    body.append('file', file);
    body.append('primary', '1');

    const { data } = await api.post<ApiResponse<StoredFile & { duplicates?: Pick<InvoiceDuplicate, 'invoice_id' | 'number'>[] }>>(
        path(`invoices/${id}/files`),
        body,
    );

    duplicates.value = data.duplicates ?? [];
    pendingFile.value = null;
}

/**
 * Save the draft (create or update it), then upload a chosen PDF as its primary document. The draft is kept as saved
 * the moment it is, so a failed upload leaves it saved: trying again updates it rather than making another.
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

        existing.value = await fetchInvoice(data.id);

        let uploadError: unknown = null;

        if (pendingFile.value && form.source === 'external') {
            try {
                await upload(data.id, pendingFile.value);
                existing.value = await fetchInvoice(data.id);
            } catch (caught) {
                uploadError = caught;
            }
        }

        dirty.value = pendingFile.value !== null;
        pending.value = false;
        savedAt.value = new Intl.DateTimeFormat('uk-UA', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Kyiv' }).format(new Date());

        if (isNew.value) {
            await router.replace({ name: 'invoice-edit', params: { id: data.id } });
        }

        if (uploadError) {
            saveError.value = uploadError;

            return null;
        }

        return existing.value;
    } catch (caught) {
        saveError.value = caught;
        revealErrors();

        return null;
    } finally {
        saving.value = false;
    }
}

/* Leaving with unsaved changes asks first (saving, freezing and deleting leave without asking) */
onBeforeRouteLeave((to) => {
    const unsaved = form.source === 'generated'
        ? (existing.value === null && pending.value) || (accordion.open.value !== null && dirty.value)
        : dirty.value;

    if (!unsaved || to.name === 'invoice-edit') {
        return true;
    }

    return window.confirm(t('invoices.editor.unsavedChanges'));
});

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
        dirty.value = false;
        await router.replace({ name: 'invoices' });
    } catch (caught) {
        deleteError.value = errorMessage(caught);
    } finally {
        deleting.value = false;
    }
}

/* The accordion groups: each edits the form, which «Скасувати» restores and «Зберегти» saves as the draft */
let backup: string | null = null;

function openGroup(): void {
    backup = JSON.stringify({ form, touched });
    saveError.value = null;
    dirty.value = false;
}

function cancelGroup(): void {
    if (backup) {
        const restored = JSON.parse(backup) as { form: typeof form; touched: typeof touched };

        Object.assign(form, restored.form);
        Object.assign(touched, restored.touched);
    }

    saveError.value = null;
    dirty.value = false;
}

async function saveGroup(): Promise<void> {
    // A new invoice keeps the group in the form: «Зберегти чернетку» stores it, once it has what a draft needs
    if (!existing.value) {
        pending.value ||= dirty.value;
        saveError.value = null;
        accordion.open.value = null;

        return;
    }

    if (await save()) {
        accordion.open.value = null;
    }
}

/** The fields of the groups, so a refused save opens the group whose fields are wrong, where they show in red. */
const GROUP_FIELDS: Record<string, string[]> = {
    client: ['client_id', 'contract_id', 'currency'],
    dates: ['period_start', 'period_end', 'issued_at', 'due_at'],
    pay: ['receiving_account_id'],
    document: [
        'locale', 'style', 'payment_purpose_template', 'show_contract', 'act_note', 'act_note_enabled', 'payment_note',
        'payment_note_enabled', 'other_note', 'other_note_enabled',
    ],
    internal: ['notes'],
};

/** Open the first group with a refused field, unless the open one has one, keeping the form as it is to cancel to. */
function revealErrors(): void {
    if (form.source !== 'generated') {
        return;
    }

    const groups = Object.entries(GROUP_FIELDS).filter(([, fields]) => fields.some((field) => fieldError(saveError.value, field)));

    if (groups.length === 0 || groups.some(([group]) => group === accordion.open.value)) {
        return;
    }

    backup = JSON.stringify({ form, touched });
    accordion.open.value = groups[0][0];
}

/** The quick periods of the month the period starts in: its halves and the whole month. */
const quickPeriods = computed(() => {
    const { year, month } = dateParts(form.period_start || today());
    const first = `${year}-${String(month).padStart(2, '0')}-01`;
    const end = endOfMonth(first);
    const half = `${first.slice(0, 8)}14`;
    const pad = (day: string) => `${day}.${String(month).padStart(2, '0')}`;

    return [
        { label: `01–${pad('14')}`, start: first, end: half },
        { label: `15–${pad(end.slice(8))}`, start: `${first.slice(0, 8)}15`, end },
        { label: monthName(month), start: first, end },
    ];
});

function pickPeriod(start: string, end: string): void {
    form.period_start = start;
    form.period_end = end;
    touched.period = true;
    dirty.value = true;
}

const generalError = computed(() => {
    if (!saveError.value) {
        return null;
    }

    const fields = [
        'client_id', 'contract_id', 'receiving_account_id', 'number', 'period_start', 'period_end', 'issued_at', 'due_at', 'total',
        'adjustment_amount', 'locale', 'payment_purpose_template', 'act_note',
    ];
    const shown = fields.some((field) => fieldError(saveError.value, field));

    return shown ? t('invoices.editor.checkFields') : errorMessage(saveError.value);
});

const itemErrors = computed(() => form.items.map((_item, index) => fieldError(saveError.value, `items.${index}.quantity`)
    ?? fieldError(saveError.value, `items.${index}.unit_price`)
    ?? fieldError(saveError.value, `items.${index}.description`)));

const title = computed(() => (isNew.value ? (form.source === 'external' ? t('invoices.editor.externalInvoice') : t('invoices.editor.newInvoice')) : t('invoices.editor.invoiceDraft')));
</script>

<template>
    <SubScreen :title="title" :back="{ name: 'invoices' }">
        <template #action>
            <StatusBadge :label="t('core.invoiceStatus.draft')" class="mr-3" />
        </template>

        <LoadState :loading="loading && !existing && clients.length === 0" :error="loadError" @retry="reload">
            <Dimmable v-if="isNew">
                <SegmentedControl v-model="form.source" :label="t('invoices.editor.invoiceSource')" :options="sourceOptions" />
            </Dimmable>

            <!-- Made in PryTax: the number the freeze will give, then the groups, which save the draft one by one -->
            <template v-if="form.source === 'generated'">
                <section class="flex flex-col gap-2 rounded-card bg-ink px-4 py-3.5 text-on-ink">
                    <div class="flex items-center justify-between">
                        <span class="text-13 text-on-ink-2">{{ t('invoices.editor.numberPreviewTitle') }}</span>
                        <button type="button" class="min-h-9 text-14 font-medium text-on-ink" @click="form.customNumber = !form.customNumber">
                            {{ form.customNumber ? t('invoices.editor.automatic') : t('invoices.editor.enterOwn') }}
                        </button>
                    </div>
                    <div v-if="form.customNumber" class="flex flex-col gap-2 rounded-control bg-surface p-3 text-ink">
                        <TextField
                            v-model="form.number"
                            :label="t('invoices.editor.customNumber')"
                            class="num"
                            :error="fieldError(saveError, 'number')"
                            @change="keepDraft"
                        />
                    </div>
                    <template v-else>
                        <span class="num text-22 font-semibold">{{ numberPreview ?? '—' }}</span>
                        <p class="m-0 text-13 leading-[1.45] text-on-ink-2">{{ numberHint }}</p>
                    </template>
                </section>

                <AccordionGroup
                    id="client"
                    :title="t('invoices.editor.clientContract')"
                    :saving="saving"
                    :error="generalError"
                    @open="openGroup"
                    @cancel="cancelGroup"
                    @save="saveGroup"
                >
                    <ReadRow :label="t('core.common.client')" :value="clients.find((client) => client.id === form.client_id)?.name" />
                    <ReadRow :label="t('core.common.contract')" :value="contract ? `${contract.name} · ${formatDate(contract.started_at)}` : t('invoices.editor.noContract')" />
                    <ReadRow v-if="contract" :label="t('invoices.editor.terms')" :value="contractHint" />
                    <template #edit>
                        <div class="contents" @input="dirty = true" @change="dirty = true">
                            <SelectField
                                v-model="form.client_id"
                                :label="t('core.common.client')"
                                :placeholder="t('invoices.editor.chooseClient')"
                                :options="clients.filter((client) => client.is_active || client.id === form.client_id).map((client) => ({ value: client.id, label: client.name }))"
                                :error="fieldError(saveError, 'client_id')"
                            />
                            <SelectField
                                :model-value="form.contract_id ?? 0"
                                :label="t('core.common.contract')"
                                :options="contractOptions"
                                :hint="contractHint"
                                :error="fieldError(saveError, 'contract_id')"
                                @update:model-value="form.contract_id = $event || null"
                            />
                        </div>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="dates"
                    :title="t('invoices.editor.periodDates')"
                    :saving="saving"
                    :error="generalError"
                    @open="openGroup"
                    @cancel="cancelGroup"
                    @save="saveGroup"
                >
                    <ReadRow :label="t('core.common.period')"><span class="num">{{ formatPeriod(form.period_start, form.period_end) }}</span></ReadRow>
                    <ReadRow :label="t('invoices.detail.issued')"><span class="num">{{ form.issued_at ? formatDate(form.issued_at) : '—' }}</span></ReadRow>
                    <ReadRow :label="t('invoices.detail.dueDate')"><span class="num">{{ form.due_at ? formatDate(form.due_at) : '—' }}</span></ReadRow>
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('invoices.editor.quick') }}</span>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="quick in quickPeriods"
                                    :key="quick.label"
                                    type="button"
                                    class="flex min-h-10 items-center rounded-full border px-3.5 text-14"
                                    :class="form.period_start === quick.start && form.period_end === quick.end
                                        ? 'border-ink bg-ink font-medium text-white'
                                        : 'border-line-input bg-surface text-ink'"
                                    @click="pickPeriod(quick.start, quick.end)"
                                >
                                    <span class="num">{{ quick.label }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2.5" @input="dirty = true" @change="dirty = true">
                            <DateRangeField
                                v-model:from="form.period_start"
                                v-model:to="form.period_end"
                                class="col-span-2"
                                :title="t('invoices.detail.servicePeriod')"
                                :from-label="t('invoices.editor.periodFrom')"
                                :to-label="t('invoices.filters.through')"
                                :from-error="fieldError(saveError, 'period_start')"
                                :to-error="fieldError(saveError, 'period_end')"
                                @update:from="touched.period = true"
                                @update:to="touched.period = true"
                            />
                            <DateField
                                v-model="form.issued_at"
                                :label="t('invoices.detail.issued')"
                                :error="fieldError(saveError, 'issued_at')"
                                @update:model-value="touched.issued_at = true"
                            />
                            <DateField
                                v-model="form.due_at"
                                :label="t('invoices.detail.dueDate')"
                                :error="fieldError(saveError, 'due_at')"
                                @update:model-value="touched.due_at = true"
                            />
                        </div>
                        <p class="m-0 text-12 text-ink-3">{{ t('invoices.editor.datesHint') }}</p>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="pay"
                    :title="t('invoices.editor.gettingPaid')"
                    :saving="saving"
                    :error="generalError"
                    @open="openGroup"
                    @cancel="cancelGroup"
                    @save="saveGroup"
                >
                    <ReadRow :label="t('money.account.account')" :value="account?.name" />
                    <ReadRow v-if="account" :label="t('core.common.recipient')">
                        {{ account.holder_name ?? '' }}<template v-if="requisites.length"> · <span class="num">{{ requisites[0]!.value }}</span></template>
                    </ReadRow>
                    <template #edit>
                        <SelectField
                            v-model="form.receiving_account_id"
                            :label="t('invoices.editor.accountPayInto')"
                            :placeholder="t('core.common.chooseAccount')"
                            :options="accountOptions"
                            :hint="t('invoices.editor.accountHint', { currency: form.currency })"
                            :error="fieldError(saveError, 'receiving_account_id')"
                            @update:model-value="touched.receiving_account_id = true; dirty = true"
                        />
                        <div v-if="account" class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2">
                            <span v-if="account.holder_name">
                                <Translated :text="t('invoices.editor.recipientHolder')">
                                    <template #holder><span class="text-ink">{{ account.holder_name }}</span></template>
                                </Translated>
                            </span>
                            <span v-if="requisites.length">
                                <template v-for="(requisite, index) in requisites" :key="requisite.label">
                                    {{ index ? ' · ' : '' }}{{ requisite.label }} <span class="num text-ink">{{ requisite.value }}</span>
                                </template>
                            </span>
                            <span>{{ t('invoices.editor.paymentDetailsHint') }}</span>
                        </div>
                    </template>
                </AccordionGroup>
                <Dimmable v-if="contract && contract.currency !== form.currency">
                    <p class="m-0 text-13 leading-normal text-warning-text" role="status">{{ t('invoices.document.currencyDiffers') }}</p>
                </Dimmable>

                <AccordionGroup
                    id="document"
                    :title="t('invoices.document.title')"
                    :saving="saving"
                    :error="generalError"
                    @open="openGroup"
                    @cancel="cancelGroup"
                    @save="saveGroup"
                >
                    <ReadRow :label="t('invoices.document.styleShort')" :value="`${styleName(documentStyle)} · ${LOCALE_NAMES[documentLocale]}`" />
                    <ReadRow
                        :label="t('invoices.document.contractShort')"
                        :value="shownDocument.showContract.value ? t('invoices.document.showShort') : t('invoices.document.hideShort')"
                    />
                    <ReadRow :label="t('invoices.editor.textsShort')">
                        {{ textsSummary }}
                        <span v-if="purposeWarned" class="mt-1 flex items-center gap-1 text-13 text-warning-text">
                            <Icon name="info" :size="16" />
                            {{ t('invoices.document.checkPurpose') }}
                        </span>
                    </ReadRow>
                    <template #edit>
                        <InvoiceDocumentLook
                            v-model="documentModel"
                            :context="documentContext"
                            :subtitle="t('invoices.document.invoiceNumbered', { number: purposeSample.number ?? '' })"
                            :target="{ invoice_id: existing?.id ?? null, client_id: form.client_id }"
                            :errors="{ locale: fieldError(saveError, 'locale'), style: fieldError(saveError, 'style') }"
                        />
                        <InvoiceDocumentTexts
                            v-model="documentModel"
                            :context="documentContext"
                            :errors="{
                                payment_purpose_template: fieldError(saveError, 'payment_purpose_template'),
                                act_note: fieldError(saveError, 'act_note'),
                                payment_note: fieldError(saveError, 'payment_note'),
                                other_note: fieldError(saveError, 'other_note'),
                            }"
                        />
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-3">
                    <SectionTitle>
                        {{ t('invoices.detail.items') }}
                        <template #aside><span class="num">{{ form.currency }}</span></template>
                    </SectionTitle>
                    <div v-for="(item, index) in form.items" :key="item.key" class="flex flex-col gap-1">
                        <InvoiceItemCard
                            :item="itemView(item, index)"
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
                        {{ t('invoices.editor.addItem') }}
                    </button>
                    <p v-if="fieldError(saveError, 'items')" class="m-0 text-12 text-negative">{{ fieldError(saveError, 'items') }}</p>
                    <button
                        v-if="!form.showAdjustment"
                        type="button"
                        class="flex min-h-11 items-center self-start text-14 font-medium text-accent"
                        @click="form.showAdjustment = true"
                    >
                        {{ t('invoices.editor.discountAdjustment') }}
                    </button>
                    <div v-else class="grid grid-cols-[2fr_1fr] gap-2.5" @change="keepDraft">
                        <TextField v-model="form.adjustment_label" :label="t('invoices.detail.adjustment')" :placeholder="t('invoices.editor.discount')" />
                        <TextField
                            v-model="form.adjustment_amount"
                            :label="t('invoices.editor.adjustmentAmount')"
                            numeric
                            placeholder="-100.00"
                            :error="fieldError(saveError, 'adjustment_amount')"
                        />
                    </div>
                    <section class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4" :aria-label="t('invoices.editor.summary')">
                        <div class="flex justify-between text-14 text-ink-2">
                            <span>{{ t('invoices.editor.subtotal') }}</span>
                            <span class="num">{{ formatAmount(subtotal) }}</span>
                        </div>
                        <div class="flex justify-between text-14 text-ink-2">
                            <span>{{ form.adjustment_label || t('invoices.detail.adjustment') }}</span>
                            <span class="num">{{ formatAmount(adjustment) }}</span>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-divider pt-2.5">
                            <span class="text-16 font-semibold">{{ t('core.common.total') }}</span>
                            <span class="num text-22 font-semibold">{{ formatMoney(total, form.currency) }}</span>
                        </div>
                    </section>
                </Dimmable>

                <AccordionGroup
                    id="internal"
                    :title="t('invoices.editor.internalNote')"
                    :saving="saving"
                    :error="generalError"
                    :rows="false"
                    @open="openGroup"
                    @cancel="cancelGroup"
                    @save="saveGroup"
                >
                    <span class="text-15 leading-[1.4] whitespace-pre-line" :class="form.notes ? '' : 'text-ink-3'">{{ form.notes || t('clients.form.noteEmpty') }}</span>
                    <template #edit>
                        <TextAreaField
                            v-model="form.notes"
                            :label="t('invoices.editor.internalNote')"
                            hide-label
                            autogrow
                            :placeholder="t('invoices.detail.notPrintedInvoice')"
                            @input="dirty = true"
                        />
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-2">
                    <p v-if="generalError && !accordion.open.value" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
                    <button
                        v-if="existing && can('delete', 'invoices')"
                        type="button"
                        class="flex min-h-11 items-center self-center text-15 font-medium text-negative"
                        @click="deleteOpen = true"
                    >
                        {{ t('invoices.editor.deleteDraft') }}
                    </button>
                </Dimmable>
            </template>

            <!-- External: the uploaded PDF, as one form -->
            <div v-else class="contents" @input="dirty = true" @change="dirty = true">
                <p class="m-0 text-15 leading-normal text-ink-2">
                    {{ t('invoices.editor.externalExplained') }}
                </p>
                <div v-if="fileName" class="flex items-center gap-3 rounded-card border border-line bg-surface p-3">
                    <span class="flex h-12 w-10 shrink-0 items-end justify-center rounded-md bg-negative-soft pb-1.5 text-11 font-bold text-negative">PDF</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate text-15">{{ fileName }}</span>
                        <span v-if="checkingFile" class="text-12 text-ink-3">{{ t('invoices.editor.checkingDuplicates') }}</span>
                        <span v-else-if="duplicates.length" class="text-12 text-warning-text">
                            <Translated :text="t('invoices.editor.duplicateFile')">
                                <template #invoices>
                                    <template v-for="(duplicate, index) in duplicates" :key="duplicate.invoice_id">
                                        {{ index ? ', ' : '' }}<RouterLink :to="{ name: 'invoice', params: { id: duplicate.invoice_id } }" class="num">{{ duplicate.number ?? t('invoices.editor.draft') }}</RouterLink>
                                    </template>
                                </template>
                            </Translated>
                        </span>
                        <span v-else-if="pendingFile && isChecked" class="text-12 text-positive">{{ t('invoices.editor.readyNoDuplicates') }}</span>
                        <span v-else-if="pendingFile" class="text-12 text-ink-3">{{ t('invoices.editor.readyServerChecks') }}</span>
                        <span v-else class="text-12 text-positive">{{ t('invoices.editor.uploadedMainDocument') }}</span>
                    </span>
                    <button v-if="pendingFile" type="button" :aria-label="t('invoices.editor.removeFile')" class="flex size-11 items-center justify-center text-ink-2" @click="dropFile">
                        <Icon name="close" :size="20" />
                    </button>
                    <button
                        v-else-if="existing?.primary_file_id"
                        type="button"
                        :aria-label="t('invoices.editor.removeFile')"
                        class="flex size-11 items-center justify-center text-ink-2"
                        @click="fileDeleteOpen = true"
                    >
                        <Icon name="close" :size="20" />
                    </button>
                </div>
                <label class="flex h-12 cursor-pointer items-center justify-center gap-2 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent">
                    <Icon name="upload" :size="20" />
                    {{ fileName ? t('invoices.editor.replacePdf') : t('invoices.editor.choosePdf') }}
                    <input ref="fileInput" type="file" accept="application/pdf,.pdf" class="sr-only" @change="chooseFile">
                </label>
                <TextField
                    v-model="form.number"
                    :label="t('invoices.editor.invoiceNumber')"
                    :hint="t('invoices.editor.customNumberHint')"
                    :error="fieldError(saveError, 'number')"
                />
                <SelectField
                    v-model="form.client_id"
                    :label="t('core.common.client')"
                    :placeholder="t('invoices.editor.chooseClient')"
                    :options="clients.filter((client) => client.is_active || client.id === form.client_id).map((client) => ({ value: client.id, label: client.name }))"
                    :error="fieldError(saveError, 'client_id')"
                />
                <div class="grid grid-cols-2 gap-2.5">
                    <DateRangeField
                        v-model:from="form.period_start"
                        v-model:to="form.period_end"
                        class="col-span-2"
                        :title="t('invoices.detail.servicePeriod')"
                        :from-label="t('invoices.editor.periodFrom')"
                        :to-label="t('invoices.filters.through')"
                        :from-error="fieldError(saveError, 'period_start')"
                        :to-error="fieldError(saveError, 'period_end')"
                        @update:from="touched.period = true"
                        @update:to="touched.period = true"
                    />
                    <DateField
                        v-model="form.issued_at"
                        :label="t('invoices.detail.issued')"
                        :error="fieldError(saveError, 'issued_at')"
                        @update:model-value="touched.issued_at = true"
                    />
                    <SelectField
                        v-model="form.currency"
                        :label="t('core.common.currency')"
                        :options="currencies"
                        @update:model-value="touched.currency = true"
                    />
                </div>
                <TextField
                    v-model="form.total"
                    :label="t('invoices.editor.invoiceAmount')"
                    numeric
                    class="text-18 font-bold"
                    :error="fieldError(saveError, 'total')"
                />
                <SelectField
                    v-model="form.receiving_account_id"
                    :label="t('invoices.detail.receivingAccount')"
                    :placeholder="t('core.common.chooseAccount')"
                    :options="accountOptions"
                    :hint="t('invoices.editor.accountHint', { currency: form.currency })"
                    :error="fieldError(saveError, 'receiving_account_id')"
                    @update:model-value="touched.receiving_account_id = true"
                />
                <section class="flex flex-col gap-3">
                    <SectionTitle>
                        {{ t('invoices.editor.itemsOptional') }}
                        <template #aside><span class="num">{{ form.currency }}</span></template>
                    </SectionTitle>
                    <div v-for="(item, index) in form.items" :key="item.key" class="flex flex-col gap-1">
                        <InvoiceItemCard
                            :item="itemView(item, index)"
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
                        {{ t('invoices.editor.addItem') }}
                    </button>
                </section>
                <TextAreaField v-model="form.notes" :label="t('invoices.editor.internalNote')" :placeholder="t('invoices.detail.notPrintedInvoice')" />
                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
                <button
                    v-if="existing && can('delete', 'invoices')"
                    type="button"
                    class="flex min-h-11 items-center self-center text-15 font-medium text-negative"
                    @click="deleteOpen = true"
                >
                    {{ t('invoices.editor.deleteDraft') }}
                </button>
            </div>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'invoices')" #footer>
            <Dimmable v-if="form.source === 'generated'" class="flex flex-col gap-2">
                <p class="m-0 text-center text-12 text-ink-3" role="status">{{ draftState }}</p>
                <div v-if="!existing" class="grid grid-cols-[1fr_1.4fr] gap-2.5">
                    <SecondaryButton :busy="saving && !freezeOpen" :disabled="loading || saving || missing.length > 0" @click="save">
                        {{ t('invoices.editor.saveDraft') }}
                    </SecondaryButton>
                    <PrimaryButton :disabled="loading || saving || missing.length > 0" @click="askFreeze">
                        <Icon name="lock" :size="20" />
                        {{ t('invoices.editor.freeze') }}
                    </PrimaryButton>
                </div>
                <PrimaryButton v-else :disabled="loading || saving" @click="askFreeze">
                    <Icon name="lock" :size="20" />
                    {{ t('invoices.editor.freeze') }}
                </PrimaryButton>
            </Dimmable>
            <div v-else class="grid grid-cols-[1fr_1.4fr] gap-2.5">
                <SecondaryButton :busy="saving && !freezeOpen" :disabled="loading || form.client_id === null" @click="save">{{ t('core.common.save') }}</SecondaryButton>
                <PrimaryButton :disabled="loading || saving || form.client_id === null" @click="askFreeze">
                    <Icon name="lock" :size="20" />
                    {{ t('invoices.editor.freeze') }}
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
            :title="t('invoices.editor.deleteDraftQuestion')"
            :confirm-label="t('core.common.delete')"
            destructive
            :busy="deleting"
            :error="deleteError"
            @confirm="remove"
        >
            <p class="m-0">{{ t('invoices.editor.deleteDraftExplained') }}</p>
        </ConfirmSheet>

        <ConfirmSheet
            v-model:open="fileDeleteOpen"
            :title="t('invoices.editor.deletePdfQuestion')"
            :confirm-label="t('core.common.delete')"
            destructive
            :busy="deletingFile"
            :error="fileDeleteError"
            @confirm="deleteUploadedFile"
        >
            <p class="m-0">{{ t('invoices.editor.deletePdfExplained') }}</p>
        </ConfirmSheet>
    </SubScreen>
</template>
