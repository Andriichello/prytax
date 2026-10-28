<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Client, InvoiceDocumentSettings, Paginated, Profile, ProfileSettings } from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import InvoiceDocumentLook from '@/components/invoice/InvoiceDocumentLook.vue';
import InvoiceDocumentTexts from '@/components/invoice/InvoiceDocumentTexts.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { type InheritedDocument, useInvoiceDocument } from '@/composables/useInvoiceDocument';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, t } from '@/i18n';
import { dateParts, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import type { PurposeSample } from '@/utils/invoiceDocument';
import { styleName } from '@/utils/invoiceStyles';

/**
 * Документ інвойсу (M-InvoiceDocument, design.md §4.1, §16.5): the ФОП's invoice defaults, which its clients and
 * invoices take unless they have their own, in two accordion groups: «Стиль, мова й договір» (the language is the
 * ФОП's document language, which the owner changes) and «Тексти в інвойсі» (the payment purpose, the act note and the
 * two notes). Each group saves its own fields; an empty one takes the built-in default.
 */
const { can } = useSession();
const { profileId, path, loadProfiles, loadSettings } = useCurrentProfile();
const accordion = provideAccordion();

const EMPTY: InvoiceDocumentSettings = {
    locale: 'en',
    payment_purpose_template: null,
    act_note_enabled: null,
    act_note: null,
    show_contract: null,
    style: null,
    payment_note_enabled: null,
    payment_note: null,
    other_note_enabled: null,
    other_note: null,
};

/** The stored settings, which the closed groups show. */
const saved = ref<InvoiceDocumentSettings>({ ...EMPTY });
/** The open group's copy of them. */
const draft = ref<InvoiceDocumentSettings>({ ...EMPTY });
const profile = ref<Profile | null>(null);
const sampleClient = ref<Client | null>(null);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [owner, settings, clients] = await Promise.all([
        api.get<ApiResponse<Profile>>(`profiles/${profileId.value}`, { append: ['effective_invoice_settings', 'invoice_texts'] }, signal),
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);
    const invoice = settings.data.invoice;

    profile.value = owner.data;
    sampleClient.value = clients.data[0] ?? null;
    saved.value = {
        locale: owner.data.locale,
        payment_purpose_template: invoice.payment_purpose_template,
        act_note_enabled: invoice.act_note_enabled,
        act_note: invoice.act_note,
        show_contract: invoice.show_contract,
        style: invoice.style,
        payment_note_enabled: invoice.payment_note_enabled,
        payment_note: invoice.payment_note,
        other_note_enabled: invoice.other_note_enabled,
        other_note: invoice.other_note,
    };

    return true;
}, [profileId]);

const canConfigure = computed(() => can('configure', 'profiles'));
const canEditLanguage = computed(() => can('update', 'profiles'));

/** The built-in settings, which the ФОП's take where it has none of its own. */
const inherited = computed<InheritedDocument | null>(() => {
    const settings = profile.value?.effective_invoice_settings;

    return settings
        ? Object.fromEntries(Object.entries(settings).map(([key, setting]) => [key, setting.inherited])) as InheritedDocument
        : null;
});

/** An invoice of the first client for this month, on which the payment purpose is previewed. */
const sample = computed<PurposeSample>(() => {
    const { year, month } = dateParts(today());
    const end = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);

    return {
        number: `${sampleClient.value?.abbreviation ?? 'BL'}-${end}-1`,
        issuedAt: end,
        periodStart: `${end.slice(0, 8)}01`,
        periodEnd: end,
        contract: null,
        client: sampleClient.value,
        profile: profile.value,
    };
});

const context = computed(() => ({
    inherited: inherited.value,
    texts: profile.value?.invoice_texts ?? null,
    sample: sample.value,
    currency: profile.value?.default_currency ?? null,
    level: 'profile' as const,
}));

const shown = useInvoiceDocument(saved, () => context.value);

function open(): void {
    draft.value = { ...saved.value };
    saveError.value = null;
}

const saving = ref(false);
const saveError = ref<unknown>(null);

/** Save the open group's fields: the look's style, contract section and language, or the texts. */
async function save(group: 'look' | 'texts'): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        if (group === 'look') {
            if (canEditLanguage.value && draft.value.locale && draft.value.locale !== profile.value?.locale) {
                await api.patch(`profiles/${profileId.value}`, { locale: draft.value.locale });
                await loadProfiles();
            }

            await api.patch(path('settings'), { invoice: { style: draft.value.style, show_contract: draft.value.show_contract } });
        } else {
            await api.patch(path('settings'), {
                invoice: {
                    payment_purpose_template: draft.value.payment_purpose_template?.trim() || null,
                    act_note_enabled: draft.value.act_note_enabled,
                    act_note: draft.value.act_note?.trim() || null,
                    payment_note_enabled: draft.value.payment_note_enabled,
                    payment_note: draft.value.payment_note?.trim() || null,
                    other_note_enabled: draft.value.other_note_enabled,
                    other_note: draft.value.other_note?.trim() || null,
                },
            });
        }

        await loadSettings();
        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const fieldErrors = computed(() => ({
    locale: fieldError(saveError.value, 'locale'),
    payment_purpose_template: fieldError(saveError.value, 'invoice.payment_purpose_template'),
    act_note: fieldError(saveError.value, 'invoice.act_note'),
    payment_note: fieldError(saveError.value, 'invoice.payment_note'),
    other_note: fieldError(saveError.value, 'invoice.other_note'),
}));
const generalError = computed(() => (saveError.value && !Object.values(fieldErrors.value).some(Boolean) ? errorMessage(saveError.value) : null));
</script>

<template>
    <SubScreen :title="t('settings.document.title')" :back="{ name: 'more' }">
        <template #aside>
            <SideNote :text="t('settings.sideNotes.document')" />
        </template>

        <LoadState :loading="loading && !profile" :error="loadError" @retry="reload">
            <Dimmable>
                <p class="m-0 text-15 leading-normal text-ink-2">{{ t('settings.document.explained') }}</p>
            </Dimmable>

            <AccordionGroup
                id="look"
                :title="t('invoices.document.lookGroup')"
                :editable="canConfigure"
                :saving="saving"
                :error="generalError"
                @open="open"
                @save="save('look')"
            >
                <ReadRow :label="t('invoices.document.styleShort')" :value="styleName(shown.style.value)" />
                <ReadRow :label="t('invoices.document.languageShort')" :value="LOCALE_NAMES[shown.locale.value]" />
                <ReadRow
                    :label="t('invoices.document.contractShort')"
                    :value="shown.showContract.value ? t('invoices.document.showShort') : t('invoices.document.hideShort')"
                />
                <template #edit>
                    <InvoiceDocumentLook
                        v-model="draft"
                        :context="context"
                        :subtitle="profile?.full_name ?? ''"
                        locale-required
                        :errors="fieldErrors"
                    />
                </template>
            </AccordionGroup>

            <AccordionGroup
                id="texts"
                :title="t('invoices.document.textsGroup')"
                :editable="canConfigure"
                :saving="saving"
                :error="generalError"
                @open="open"
                @save="save('texts')"
            >
                <ReadRow
                    :label="t('invoices.document.purposeShort')"
                    :value="saved.payment_purpose_template === null ? t('invoices.document.defaultPurpose') : t('invoices.document.own')"
                />
                <ReadRow
                    :label="t('invoices.document.actNote')"
                    :value="shown.actEnabled.value ? t('invoices.document.onShort') : t('invoices.document.offShort')"
                />
                <ReadRow :label="t('invoices.document.paymentNoteShort')" :value="shown.noteState('payment_note')" />
                <ReadRow :label="t('invoices.document.otherNoteTitle')" :value="shown.noteState('other_note')" />
                <template #edit>
                    <InvoiceDocumentTexts v-model="draft" :context="context" :errors="fieldErrors" />
                </template>
            </AccordionGroup>

            <Dimmable class="flex flex-col gap-3">
                <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('invoices.document.changesDrafts') }}</p>
                <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
            </Dimmable>
        </LoadState>
    </SubScreen>
</template>
