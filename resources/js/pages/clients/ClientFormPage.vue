<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, Client, Contract, Profile } from '@/api/types';
import ClientAddressFields from '@/components/client/ClientAddressFields.vue';
import { CLIENT_CURRENCIES, type ClientForm } from '@/components/client/ClientFormModel';
import ClientMainFields from '@/components/client/ClientMainFields.vue';
import ClientTaxFields from '@/components/client/ClientTaxFields.vue';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import InvoiceDocumentLook from '@/components/invoice/InvoiceDocumentLook.vue';
import InvoiceDocumentTexts from '@/components/invoice/InvoiceDocumentTexts.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { type InheritedDocument, useInvoiceDocument } from '@/composables/useInvoiceDocument';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, t } from '@/i18n';
import { clientAddressText, countryName, suggestAbbreviation } from '@/utils/clients';
import { dateParts, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import type { PurposeSample } from '@/utils/invoiceDocument';
import { styleName } from '@/utils/invoiceStyles';

/**
 * Клієнт — новий або редагування (M-ClientEdit, design.md §16.5). A new client is one form with its own «Зберегти».
 * An existing one is an accordion, each group saved on its own: Основне (the name, the abbreviation, which prefixes
 * its invoice numbers, the legal names and the country), Адреса в інвойсі, Податкові дані й пошта, Валюта, мова,
 * договір і стиль (`#invoice-defaults`, which the client's page opens), Тексти в інвойсі and Внутрішня примітка.
 * The invoice settings are each empty to take the ФОП's (design.md §4.1).
 */
type Group = 'main' | 'address' | 'tax' | 'look' | 'texts' | 'notes';

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();
const accordion = provideAccordion();

const clientId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => clientId.value === null);

const blank = (): ClientForm => ({
    name: '',
    abbreviation: '',
    legal_name: '',
    legal_name_uk: '',
    country: 'US',
    currency: null,
    address: '',
    tax_id: '',
    vat_number: '',
    email: '',
    notes: '',
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
    },
});

function formOf(client: Client): ClientForm {
    return {
        name: client.name,
        abbreviation: client.abbreviation,
        legal_name: client.legal_name ?? '',
        legal_name_uk: client.legal_name_uk ?? '',
        country: client.country ?? '',
        currency: client.currency,
        address: clientAddressText(client.address),
        tax_id: client.tax_id ?? '',
        vat_number: client.vat_number ?? '',
        email: client.email ?? '',
        notes: client.notes ?? '',
        document: {
            locale: client.invoice_locale,
            payment_purpose_template: client.payment_purpose_template,
            act_note_enabled: client.act_note_enabled,
            act_note: client.act_note,
            show_contract: client.show_contract,
            style: client.invoice_style,
            payment_note_enabled: client.payment_note_enabled,
            payment_note: client.payment_note,
            other_note_enabled: client.other_note_enabled,
            other_note: client.other_note,
        },
    };
}

/** The form: a new client's fields, or the open group's copy of the client's. */
const form = ref<ClientForm>(blank());
/** The client as saved, which the closed groups show. */
const savedForm = ref<ClientForm>(blank());

/** Whether the abbreviation was typed (or is the saved one), so the name no longer suggests it. */
const abbreviationTyped = ref(false);

/** The ФОП with its invoice settings, which the client's take, and the built-in texts. */
const profile = ref<Profile | null>(null);

const { data: existing, error: loadError, loading, reload } = useLoader(async (signal) => {
    const [owner, client] = await Promise.all([
        api.get<ApiResponse<Profile>>(`profiles/${profileId.value}`, { append: ['effective_invoice_settings', 'invoice_texts'] }, signal),
        clientId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Client>>(path(`clients/${clientId.value}`), { include: ['contracts'] }, signal),
    ]);

    profile.value = owner.data;

    if (client === null) {
        return null;
    }

    abbreviationTyped.value = true;
    savedForm.value = formOf(client.data);

    return client.data;
}, [profileId, clientId]);

/** Opened from the client's page at its invoice defaults: open that group once the client is loaded. */
watch(existing, async (client) => {
    if (client && route.hash === '#invoice-defaults' && accordion.open.value === null && canSave.value) {
        open();
        accordion.open.value = 'look';
        await nextTick();
        document.getElementById('invoice-defaults')?.scrollIntoView({ block: 'start' });
    }
});

watch(() => form.value.name, (name) => {
    if (!abbreviationTyped.value) {
        form.value.abbreviation = suggestAbbreviation(name);
    }
});

const canSave = computed(() => can(isNew.value ? 'create' : 'update', 'clients'));

/** The ФОП's settings, which the client's take where it has none of its own. */
const inherited = computed<InheritedDocument | null>(() => profile.value?.effective_invoice_settings ?? null);

/** An invoice of the client for this month, on which the payment purpose is previewed. */
function sampleOf(values: ClientForm): PurposeSample {
    const { year, month } = dateParts(today());
    const end = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
    const contract = existing.value?.contracts?.find((item: Contract) => item.is_active) ?? null;

    return {
        number: `${values.abbreviation.trim() || 'BL'}-${end}-1`,
        issuedAt: end,
        periodStart: `${end.slice(0, 8)}01`,
        periodEnd: end,
        contract,
        client: { name: values.name, legal_name: values.legal_name || null, name_uk: existing.value?.name_uk ?? null, legal_name_uk: values.legal_name_uk || null },
        profile: profile.value,
    };
}

const contextOf = (values: ClientForm) => ({
    inherited: inherited.value,
    texts: profile.value?.invoice_texts ?? null,
    sample: sampleOf(values),
    currency: values.currency ?? profile.value?.default_currency ?? null,
    level: 'client' as const,
});
const context = computed(() => contextOf(form.value));

/** The invoice settings of the closed groups. */
const savedDocument = computed({ get: () => savedForm.value.document, set: () => undefined });
const shown = useInvoiceDocument(savedDocument, () => contextOf(savedForm.value));

/** The document settings of the form, as the document components edit them. */
const documentModel = computed({
    get: () => form.value.document,
    set: (value) => {
        form.value.document = value;
    },
});

const currencyOptions = CLIENT_CURRENCIES.map((currency) => ({ value: currency, label: currency }));
const currencyPlaceholder = computed(() => t('invoices.document.inheritedValue', {
    source: t('invoices.document.asProfile'),
    value: profile.value?.default_currency ?? '',
}));

/** «USD · як у ФОП», «Colored · власне». */
const withSource = (value: string, own: boolean): string => t('clients.form.withSource', {
    value,
    source: own ? t('clients.form.sourceOwn') : t('clients.form.sourceProfile'),
});

/** The fields of each group, as the API takes them. */
function fields(group: Group | 'all'): Record<string, unknown> {
    const values = form.value;
    const doc = values.document;
    const parts: Record<Group, Record<string, unknown>> = {
        main: {
            name: values.name.trim(),
            abbreviation: values.abbreviation.trim(),
            legal_name: values.legal_name.trim() || null,
            legal_name_uk: values.legal_name_uk.trim() || null,
            country: values.country || null,
        },
        address: { address: values.address.trim() ? { text: values.address.trim() } : null },
        tax: { tax_id: values.tax_id.trim() || null, vat_number: values.vat_number.trim() || null, email: values.email.trim() || null },
        look: { currency: values.currency || null, invoice_locale: doc.locale, show_contract: doc.show_contract, invoice_style: doc.style },
        texts: {
            payment_purpose_template: doc.payment_purpose_template?.trim() || null,
            act_note_enabled: doc.act_note_enabled,
            act_note: doc.act_note?.trim() || null,
            payment_note_enabled: doc.payment_note_enabled,
            payment_note: doc.payment_note?.trim() || null,
            other_note_enabled: doc.other_note_enabled,
            other_note: doc.other_note?.trim() || null,
        },
        notes: { notes: values.notes.trim() || null },
    };

    return group === 'all' ? Object.assign({}, ...Object.values(parts)) : parts[group];
}

const saving = ref(false);
const saveError = ref<unknown>(null);

function open(): void {
    form.value = JSON.parse(JSON.stringify(savedForm.value)) as ClientForm;
    saveError.value = null;
}

async function saveGroup(group: Group): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.patch(path(`clients/${clientId.value}`), fields(group));
        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

async function create(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        const { data } = await api.post<ApiResponse<Client>>(path('clients'), fields('all'));

        await router.replace({ name: 'client', params: { id: data.id } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const errors = computed(() => ({
    name: fieldError(saveError.value, 'name'),
    abbreviation: /^[A-Z0-9]*$/.test(form.value.abbreviation.trim())
        ? fieldError(saveError.value, 'abbreviation')
        : t('clients.form.latinLettersDigits'),
    legal_name: fieldError(saveError.value, 'legal_name'),
    legal_name_uk: fieldError(saveError.value, 'legal_name_uk'),
    country: fieldError(saveError.value, 'country'),
    tax_id: fieldError(saveError.value, 'tax_id'),
    vat_number: fieldError(saveError.value, 'vat_number'),
    email: fieldError(saveError.value, 'email'),
    currency: fieldError(saveError.value, 'currency'),
}));
const documentErrors = computed(() => ({
    locale: fieldError(saveError.value, 'invoice_locale'),
    payment_purpose_template: fieldError(saveError.value, 'payment_purpose_template'),
    act_note: fieldError(saveError.value, 'act_note'),
    style: fieldError(saveError.value, 'invoice_style'),
    payment_note: fieldError(saveError.value, 'payment_note'),
    other_note: fieldError(saveError.value, 'other_note'),
}));
const generalError = computed(() => (saveError.value
    && !Object.values(errors.value).some(Boolean) && !Object.values(documentErrors.value).some(Boolean)
    ? errorMessage(saveError.value)
    : null));

const abbreviationValid = computed(() => /^[A-Z0-9]+$/.test(form.value.abbreviation.trim()));

</script>

<template>
    <SubScreen
        :title="isNew ? t('clients.form.newClient') : existing?.name ?? t('core.common.client')"
        :back="isNew ? { name: 'clients' } : { name: 'client', params: { id: clientId } }"
    >
        <template #aside>
            <SideNote :text="t('settings.sideNotes.client')" />
        </template>

        <LoadState :loading="loading && !profile" :error="loadError" @retry="reload">
            <template v-if="isNew">
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <ClientMainFields v-model="form" :errors="errors" @abbreviation-typed="abbreviationTyped = $event" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('clients.form.invoiceAddress') }}</h2>
                    <ClientAddressFields v-model="form" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('clients.form.taxDetails') }}</h2>
                    <ClientTaxFields v-model="form" :errors="errors" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('clients.form.lookGroup') }}</h2>
                    <SelectField
                        v-model="form.currency"
                        :label="t('core.common.currency')"
                        :options="currencyOptions"
                        :placeholder="currencyPlaceholder"
                        clearable
                        :hint="t('clients.form.currencyHint')"
                        :error="errors.currency"
                    />
                    <InvoiceDocumentLook v-model="documentModel" :context="context" :subtitle="form.name" :errors="documentErrors" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('invoices.document.textsGroup') }}</h2>
                    <InvoiceDocumentTexts v-model="documentModel" :context="context" :errors="documentErrors" />
                </section>
                <TextAreaField v-model="form.notes" :label="t('invoices.editor.internalNote')" :rows="2" :placeholder="t('clients.form.notPrinted')" />
                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
            </template>

            <template v-else-if="existing">
                <AccordionGroup id="main" :title="t('clients.form.mainGroup')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="abbreviationValid && saveGroup('main')">
                    <ReadRow :label="t('core.common.name')" :value="savedForm.name" />
                    <ReadRow :label="t('clients.form.abbreviation')"><span class="num">{{ savedForm.abbreviation }}</span></ReadRow>
                    <ReadRow :label="t('clients.form.legalName')" :value="savedForm.legal_name" />
                    <ReadRow :label="t('clients.form.legalNameUk')" :value="savedForm.legal_name_uk" />
                    <ReadRow :label="t('clients.form.country')" :value="savedForm.country ? `${countryName(savedForm.country)} · ${savedForm.country}` : null" />
                    <template #edit>
                        <ClientMainFields v-model="form" :errors="errors" @abbreviation-typed="abbreviationTyped = $event" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="address" :title="t('clients.form.invoiceAddress')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="saveGroup('address')">
                    <ReadRow :label="t('core.common.address')">
                        <span class="whitespace-pre-line">{{ savedForm.address.trim() || '—' }}</span>
                    </ReadRow>
                    <template #edit>
                        <ClientAddressFields v-model="form" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="tax" :title="t('clients.form.taxDetails')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="saveGroup('tax')">
                    <ReadRow :label="t('clients.detail.taxNumber')"><span class="num">{{ savedForm.tax_id || '—' }}</span></ReadRow>
                    <ReadRow label="VAT"><span class="num">{{ savedForm.vat_number || '—' }}</span></ReadRow>
                    <ReadRow label="Email" :value="savedForm.email" />
                    <template #edit>
                        <ClientTaxFields v-model="form" :errors="errors" />
                    </template>
                </AccordionGroup>

                <div id="invoice-defaults" class="scroll-mt-4">
                    <AccordionGroup id="look" :title="t('clients.form.lookGroup')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="saveGroup('look')">
                        <ReadRow
                            :label="t('core.common.currency')"
                            :value="withSource(savedForm.currency ?? profile?.default_currency ?? '', savedForm.currency !== null)"
                        />
                        <ReadRow
                            :label="t('invoices.document.languageShort')"
                            :value="withSource(LOCALE_NAMES[shown.locale.value], savedForm.document.locale !== null)"
                        />
                        <ReadRow
                            :label="t('invoices.document.contractShort')"
                            :value="withSource(shown.showContract.value ? t('invoices.document.showShort') : t('invoices.document.hideShort'), savedForm.document.show_contract !== null)"
                        />
                        <ReadRow
                            :label="t('invoices.document.styleShort')"
                            :value="withSource(styleName(shown.style.value), savedForm.document.style !== null)"
                        />
                        <template #edit>
                            <SelectField
                                v-model="form.currency"
                                :label="t('core.common.currency')"
                                :aside="form.currency === null ? undefined : t('invoices.document.own')"
                                :options="currencyOptions"
                                :placeholder="currencyPlaceholder"
                                clearable
                                :hint="t('clients.form.currencyHint')"
                                :error="errors.currency"
                            />
                            <InvoiceDocumentLook
                                v-model="documentModel"
                                :context="context"
                                :subtitle="form.name || existing.name"
                                :target="{ client_id: clientId }"
                                :errors="documentErrors"
                            />
                        </template>
                    </AccordionGroup>
                </div>

                <AccordionGroup id="texts" :title="t('invoices.document.textsGroup')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="saveGroup('texts')">
                    <ReadRow :label="t('invoices.document.purposeShort')">
                        {{ savedForm.document.payment_purpose_template === null ? t('invoices.document.defaultPurpose') : t('invoices.document.own') }}
                        <span v-if="shown.purposeWarningCodes.value.includes('payment_purpose_too_long')" class="block text-13 text-warning-text">
                            {{ t('invoices.document.tooLong', { length: 140 }) }}
                        </span>
                    </ReadRow>
                    <ReadRow :label="t('invoices.document.actNote')" :value="shown.actEnabled.value ? t('invoices.document.onShort') : t('invoices.document.offShort')" />
                    <ReadRow :label="t('invoices.document.paymentNoteShort')" :value="shown.noteState('payment_note')" />
                    <ReadRow :label="t('invoices.document.otherNoteTitle')" :value="shown.noteState('other_note')" />
                    <template #edit>
                        <InvoiceDocumentTexts v-model="documentModel" :context="context" :errors="documentErrors" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="notes" :title="t('invoices.editor.internalNote')" :editable="canSave" :saving="saving" :error="generalError" :rows="false" @open="open" @save="saveGroup('notes')">
                    <span class="text-15 leading-[1.4] whitespace-pre-line" :class="savedForm.notes ? '' : 'text-ink-3'">{{ savedForm.notes || t('clients.form.noteEmpty') }}</span>
                    <template #edit>
                        <TextAreaField v-model="form.notes" :label="t('invoices.editor.internalNote')" hide-label autogrow :placeholder="t('clients.form.notPrinted')" />
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-3">
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('invoices.document.changesDrafts') }}</p>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
                </Dimmable>
            </template>
        </LoadState>

        <template v-if="isNew && canSave" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim() || !abbreviationValid" @click="create">{{ t('core.common.save') }}</PrimaryButton>
        </template>
    </SubScreen>
</template>
