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
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import FreezeSheet from '@/components/invoice/FreezeSheet.vue';
import InvoiceDocumentFields from '@/components/invoice/InvoiceDocumentFields.vue';
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
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, t } from '@/i18n';
import { dateParts, formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { type PurposeSample, purposeValues, purposeWarnings, renderPurpose } from '@/utils/invoiceDocument';
import { formatAmount, formatMoney, multiplyAmounts, parseAmount, sumAmounts } from '@/utils/money';

/**
 * Новий інвойс / чернетка (S-InvoiceEdit, S-InvoiceExternal): an invoice made in PryTax from items, or an external
 * PDF with its total typed in. "Зберегти" keeps the draft; "Зафіксувати" saves it and asks before freezing. Leaving
 * with unsaved changes asks first. A generated one has its document settings (language, payment purpose, act note,
 * contract section) in a collapsed "Документ" block, each the client's unless the invoice has its own.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings, profile } = useCurrentProfile();

const invoiceId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => invoiceId.value === null);

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
    document: { locale: null, payment_purpose_template: null, act_note_enabled: null, act_note: null, show_contract: null } as InvoiceDocumentSettings,
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
 * What the form starts with for the client, contract and period: currency, dates, account, the contract's
 * description and rates, and the number the freeze would give. An external invoice has no contract: its currency is
 * the client's, or the one typed.
 */
watch(() => [
    form.client_id,
    form.source === 'generated' ? form.contract_id : null,
    form.period_end,
    touched.issued_at ? form.issued_at : '',
    form.source === 'external' && touched.currency ? form.currency : '',
] as const, async ([clientId, contractId, periodEnd, issuedAt, currency]) => {
    if (clientId === null) {
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
const documentOpen = ref(false);

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

/** What the invoice prints, in short: "English · з приміткою «акт» · з договором". */
const documentSummary = computed(() => {
    const inherited = defaults.value?.effective_settings;
    const locale = form.document.locale ?? inherited?.locale.value ?? 'en';
    const act = form.document.act_note_enabled ?? inherited?.act_note_enabled.value ?? true;
    const shown = form.document.show_contract ?? inherited?.show_contract.value ?? true;

    return [
        LOCALE_NAMES[locale],
        act ? t('invoices.document.withActNote') : t('invoices.document.withoutActNote'),
        shown ? t('invoices.document.withContract') : t('invoices.document.withoutContract'),
    ].join(' · ');
});

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

    return t('invoices.editor.numberOnFreeze', { parts: parts.filter(Boolean).join(' + ') });
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
}

function removeItem(key: number): void {
    dirty.value = true;
    form.items = form.items.filter((item) => item.key !== key);
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

/* Saving, freezing and deleting */
const saving = ref(false);
const saveError = ref<unknown>(null);
const savedAt = ref<string | null>(null);

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

        return null;
    } finally {
        saving.value = false;
    }
}

/* Leaving with unsaved changes asks first (saving, freezing and deleting leave without asking) */
onBeforeRouteLeave((to) => {
    if (!dirty.value || to.name === 'invoice-edit') {
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

        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <div class="contents" @input="dirty = true" @change="dirty = true">
                <SegmentedControl
                    v-if="isNew"
                    v-model="form.source"
                    :label="t('invoices.editor.invoiceSource')"
                    :options="sourceOptions"
                />

                <!-- Generated: the number the freeze will give -->
                <section v-if="form.source === 'generated'" class="flex flex-col gap-2.5 rounded-card border border-line bg-surface p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-13 font-medium text-ink-2">{{ t('core.common.number') }}</span>
                        <button
                            type="button"
                            class="min-h-9 text-14 font-medium text-accent"
                            @click="form.customNumber = !form.customNumber"
                        >
                            {{ form.customNumber ? t('invoices.editor.automatic') : t('invoices.editor.enterOwn') }}
                        </button>
                    </div>
                    <TextField
                        v-if="form.customNumber"
                        v-model="form.number"
                        :label="t('invoices.editor.customNumber')"
                        class="num"
                        :error="fieldError(saveError, 'number')"
                    />
                    <template v-else>
                        <div class="flex items-baseline gap-2.5">
                            <span class="num text-22 font-semibold">{{ numberPreview ?? '—' }}</span>
                            <span class="text-12 text-ink-3">{{ t('invoices.editor.preview') }}</span>
                        </div>
                        <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ numberHint }}</p>
                    </template>
                </section>

                <!-- External: the uploaded PDF -->
                <template v-else>
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
                </template>

                <section class="flex flex-col gap-3">
                    <SectionTitle>{{ form.source === 'generated' ? t('invoices.editor.clientContract') : t('core.common.client') }}</SectionTitle>
                    <SelectField
                        v-model="form.client_id"
                        :label="t('core.common.client')"
                        :placeholder="t('invoices.editor.chooseClient')"
                        :options="clients.filter((client) => client.is_active || client.id === form.client_id).map((client) => ({ value: client.id, label: client.name }))"
                        :error="fieldError(saveError, 'client_id')"
                    />
                    <SelectField
                        v-if="form.source === 'generated'"
                        :model-value="form.contract_id ?? 0"
                        :label="t('core.common.contract')"
                        :options="contractOptions"
                        :hint="contractHint"
                        :error="fieldError(saveError, 'contract_id')"
                        @update:model-value="form.contract_id = $event || null"
                    />
                </section>

                <section class="flex flex-col gap-3">
                    <SectionTitle>{{ t('invoices.editor.periodDates') }}</SectionTitle>
                    <div class="grid grid-cols-2 gap-2.5">
                        <DateField
                            v-model="form.period_start"
                            :label="t('invoices.editor.periodFrom')"
                            :error="fieldError(saveError, 'period_start')"
                            @update:model-value="touched.period = true"
                        />
                        <DateField
                            v-model="form.period_end"
                            :label="t('invoices.filters.through')"
                            :min="form.period_start || undefined"
                            :error="fieldError(saveError, 'period_end')"
                            @update:model-value="touched.period = true"
                        />
                        <DateField
                            v-model="form.issued_at"
                            :label="form.source === 'generated' ? t('invoices.editor.issueDate') : t('invoices.detail.issued')"
                            :error="fieldError(saveError, 'issued_at')"
                            @update:model-value="touched.issued_at = true"
                        />
                        <DateField
                            v-if="form.source === 'generated'"
                            v-model="form.due_at"
                            :label="t('invoices.detail.dueDate')"
                            :error="fieldError(saveError, 'due_at')"
                            @update:model-value="touched.due_at = true"
                        />
                        <SelectField
                            v-else
                            v-model="form.currency"
                            :label="t('core.common.currency')"
                            :options="currencies"
                            @update:model-value="touched.currency = true"
                        />
                    </div>
                </section>

                <TextField
                    v-if="form.source === 'external'"
                    v-model="form.total"
                    :label="t('invoices.editor.invoiceAmount')"
                    numeric
                    class="text-18 font-bold"
                    :error="fieldError(saveError, 'total')"
                />

                <section class="flex flex-col gap-3">
                    <SectionTitle v-if="form.source === 'generated'">{{ t('invoices.editor.gettingPaid') }}</SectionTitle>
                    <SelectField
                        v-model="form.receiving_account_id"
                        :label="form.source === 'generated' ? t('invoices.editor.accountPayInto') : t('invoices.detail.receivingAccount')"
                        :placeholder="t('core.common.chooseAccount')"
                        :options="accountOptions"
                        :hint="t('invoices.editor.accountHint', { currency: form.currency })"
                        :error="fieldError(saveError, 'receiving_account_id')"
                        @update:model-value="touched.receiving_account_id = true"
                    />
                    <div
                        v-if="form.source === 'generated' && account"
                        class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2"
                    >
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
                    <p v-if="form.source === 'generated' && contract && contract.currency !== form.currency" class="m-0 text-13 leading-normal text-warning-text" role="status">
                        {{ t('invoices.document.currencyDiffers') }}
                    </p>
                </section>

                <section v-if="form.source === 'generated'" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                    <button
                        type="button"
                        class="flex min-h-11 items-center justify-between gap-3 text-left"
                        :aria-expanded="documentOpen ? 'true' : 'false'"
                        @click="documentOpen = !documentOpen"
                    >
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-16 font-semibold">{{ t('invoices.document.title') }}</span>
                            <span class="text-13 text-ink-3">{{ documentSummary }}</span>
                            <span v-if="purposeWarned" class="flex items-center gap-1 text-13 text-warning-text">
                                <Icon name="info" :size="16" />
                                {{ t('invoices.document.checkPurpose') }}
                            </span>
                        </span>
                        <Icon name="chevronDown" :size="20" class="shrink-0 text-ink-2 transition-transform" :class="{ 'rotate-180': documentOpen }" />
                    </button>
                    <InvoiceDocumentFields
                        v-if="documentOpen"
                        v-model="form.document"
                        level="invoice"
                        :inherited="defaults?.effective_settings ?? null"
                        :texts="texts"
                        :sample="purposeSample"
                        :currency="form.currency"
                        :errors="{
                            locale: fieldError(saveError, 'locale'),
                            payment_purpose_template: fieldError(saveError, 'payment_purpose_template'),
                            act_note: fieldError(saveError, 'act_note'),
                        }"
                    />
                </section>

                <section class="flex flex-col gap-3">
                    <SectionTitle>
                        {{ form.source === 'external' ? t('invoices.editor.itemsOptional') : t('invoices.detail.items') }}
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
                        {{ t('invoices.editor.addItem') }}
                    </button>
                    <p v-if="fieldError(saveError, 'items')" class="m-0 text-12 text-negative">{{ fieldError(saveError, 'items') }}</p>

                    <template v-if="form.source === 'generated'">
                        <button
                            v-if="!form.showAdjustment"
                            type="button"
                            class="flex min-h-11 items-center self-start text-14 font-medium text-accent"
                            @click="form.showAdjustment = true"
                        >
                            {{ t('invoices.editor.discountAdjustment') }}
                        </button>
                        <div v-else class="grid grid-cols-[2fr_1fr] gap-2.5">
                            <TextField v-model="form.adjustment_label" :label="t('invoices.detail.adjustment')" :placeholder="t('invoices.editor.discount')" />
                            <TextField
                                v-model="form.adjustment_amount"
                                :label="t('invoices.editor.adjustmentAmount')"
                                numeric
                                placeholder="-100.00"
                                :error="fieldError(saveError, 'adjustment_amount')"
                            />
                        </div>
                    </template>
                </section>

                <section v-if="form.source === 'generated'" class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4" :aria-label="t('invoices.editor.summary')">
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

                <TextAreaField v-model="form.notes" :label="t('invoices.editor.internalNote')" :placeholder="t('invoices.detail.notPrintedInvoice')" />

                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
                <p v-else-if="savedAt && !saving" class="m-0 text-13 text-ink-3" role="status">
                    <Translated :text="t('invoices.editor.draftSaved')">
                        <template #time><span class="num">{{ savedAt }}</span></template>
                    </Translated>
                </p>
            </div>

            <button
                v-if="existing && can('delete', 'invoices')"
                type="button"
                class="flex min-h-11 items-center self-center text-15 font-medium text-negative"
                @click="deleteOpen = true"
            >
                {{ t('invoices.editor.deleteDraft') }}
            </button>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'invoices')" #footer>
            <div class="grid grid-cols-[1fr_1.4fr] gap-2.5">
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
    </SubScreen>
</template>
