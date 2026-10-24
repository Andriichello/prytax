<script setup lang="ts">
import { computed, ref } from 'vue';
import type { EffectiveInvoiceSettings, InvoiceDocumentSettings, InvoiceLocale, InvoiceTexts, SettingSource } from '@/api/types';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import SwitchTile from '@/components/ui/SwitchTile.vue';
import { LOCALE_NAMES, t } from '@/i18n';
import {
    PURPOSE_PLACEHOLDERS,
    type PurposeSample,
    purposeValues,
    purposeWarnings,
    REFERENCE_LENGTH,
    renderPurpose,
    unknownPlaceholders,
} from '@/utils/invoiceDocument';

/**
 * The document settings of an invoice, of a client's invoices or of the ФОП's (design.md §4.1): the language, the
 * payment purpose (with its placeholders and a preview of it on the invoice), the act note and the contract section.
 * Each is the level's own, or empty to take the one below it, which shows beside it ("Як у ФОП: English") with a
 * reset of the own one. The built-in texts follow the language chosen on the form. At the ФОП's level the language is
 * its document language, which is never empty.
 */
type Inherited = { [K in keyof EffectiveInvoiceSettings]: { value: EffectiveInvoiceSettings[K]['value']; source: SettingSource } };

const {
    inherited,
    texts,
    sample,
    currency,
    level,
    localeRequired = false,
    localeDisabled = false,
    localeHint = undefined,
    disabled = false,
    errors = {},
} = defineProps<{
    /** The values of the levels below this one. */
    inherited: Inherited | null;
    /** The built-in texts in each language. */
    texts: Record<InvoiceLocale, InvoiceTexts> | null;
    /** The invoice the preview fills the payment purpose for. */
    sample: PurposeSample;
    /** The invoice's currency, in which a payment purpose is checked against SWIFT, unless it is hryvnias. */
    currency: string | null;
    level: 'invoice' | 'client' | 'profile';
    localeRequired?: boolean;
    localeDisabled?: boolean;
    localeHint?: string;
    disabled?: boolean;
    /** Errors of the fields from the API, by their names. */
    errors?: Partial<Record<keyof InvoiceDocumentSettings, string | undefined>>;
}>();

const model = defineModel<InvoiceDocumentSettings>({ required: true });

const locale = computed<InvoiceLocale>(() => model.value.locale ?? inherited?.locale.value ?? 'en');

/** The inherited value of a setting; a built-in text in the language chosen on the form. */
function inheritedText(key: 'payment_purpose_template' | 'act_note'): string {
    const setting = inherited?.[key];

    if (setting?.source === 'default' && texts) {
        return texts[locale.value][key === 'act_note' ? 'act_note' : 'payment_purpose'];
    }

    return setting?.value ?? '';
}

/** "Як у ФОП", "Як у клієнта" or "Типово". */
function sourceName(source: SettingSource | undefined): string {
    switch (source) {
        case 'client':
            return t('invoices.document.asClient');
        case 'profile':
            return t('invoices.document.asProfile');
        default:
            return t('invoices.document.asDefault');
    }
}

/** What the levels below give a setting: "Як у ФОП: English". */
function inheritedNote(source: SettingSource | undefined, value?: string): string {
    return value ? t('invoices.document.inheritedValue', { source: sourceName(source), value }) : sourceName(source);
}

const onOff = (value: boolean | undefined): string => (value ? t('invoices.document.on') : t('invoices.document.off'));

const resetLabel = computed(() => (level === 'invoice' ? t('invoices.document.resetToClient') : t('invoices.document.reset')));

/** Change one setting of the model. */
function set<K extends keyof InvoiceDocumentSettings>(key: K, value: InvoiceDocumentSettings[K]): void {
    model.value = { ...model.value, [key]: value };
}

const localeOptions = computed(() => (['en', 'uk'] as const).map((code) => ({ value: code, label: LOCALE_NAMES[code] })));
const localePlaceholder = computed(() => (localeRequired
    ? undefined
    : inheritedNote(inherited?.locale.source, inherited ? LOCALE_NAMES[inherited.locale.value] : undefined)));

/* The payment purpose: its template, the placeholders typed at the caret, and the preview on the sample invoice */
const purposeTemplate = computed({
    get: () => model.value.payment_purpose_template ?? '',
    set: (value: string) => set('payment_purpose_template', value === '' ? null : value),
});

const caret = ref<number | null>(null);

function rememberCaret(event: Event): void {
    if (event.target instanceof HTMLTextAreaElement) {
        caret.value = event.target.selectionStart;
    }
}

/** The placeholders as they are typed: `{services}`. */
const TOKENS = PURPOSE_PLACEHOLDERS.map((name) => `{${name}}`);

function insertToken(token: string): void {
    const text = purposeTemplate.value;
    const at = Math.min(caret.value ?? text.length, text.length);

    purposeTemplate.value = text.slice(0, at) + token + text.slice(at);
    caret.value = at + token.length;
}

const preview = computed(() => renderPurpose(
    purposeTemplate.value || inheritedText('payment_purpose_template'),
    purposeValues(sample, locale.value, texts?.[locale.value] ?? null),
));
const warnings = computed(() => purposeWarnings(preview.value, currency));
const unknown = computed(() => unknownPlaceholders(purposeTemplate.value));
const purposeError = computed(() => (unknown.value.length
    ? t('invoices.document.unknownPlaceholders', { placeholders: unknown.value.map((name) => `{${name}}`).join(', ') })
    : errors.payment_purpose_template));

/* The act note and the contract section: on or off of the level's own, or the one below it */
const actEnabled = computed({
    get: () => model.value.act_note_enabled ?? inherited?.act_note_enabled.value ?? true,
    set: (value: boolean) => set('act_note_enabled', value),
});
const actNote = computed({
    get: () => model.value.act_note ?? '',
    set: (value: string) => set('act_note', value === '' ? null : value),
});
const showContract = computed({
    get: () => model.value.show_contract ?? inherited?.show_contract.value ?? true,
    set: (value: boolean) => set('show_contract', value),
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
            <SelectField
                :model-value="model.locale"
                :label="t('invoices.document.language')"
                :options="localeOptions"
                :placeholder="localePlaceholder"
                :clearable="!localeRequired"
                :disabled="disabled || localeDisabled"
                :hint="localeHint"
                :error="errors.locale"
                @update:model-value="set('locale', $event)"
            />
        </div>

        <div class="flex flex-col gap-1.5" @keyup="rememberCaret" @click="rememberCaret" @input="rememberCaret">
            <TextAreaField
                v-model="purposeTemplate"
                :label="t('invoices.document.paymentPurpose')"
                :rows="3"
                :placeholder="inheritedText('payment_purpose_template')"
                :disabled="disabled"
                :error="purposeError"
            />
            <div class="flex items-center justify-between gap-2 text-12 text-ink-3">
                <span>{{ model.payment_purpose_template === null ? inheritedNote(inherited?.payment_purpose_template.source) : '' }}</span>
                <button
                    v-if="!disabled && model.payment_purpose_template === null"
                    type="button"
                    class="min-h-9 text-13 font-medium text-accent"
                    @click="purposeTemplate = inheritedText('payment_purpose_template')"
                >
                    {{ t('invoices.document.edit') }}
                </button>
                <button v-else-if="!disabled" type="button" class="min-h-9 text-13 font-medium text-accent" @click="set('payment_purpose_template', null)">
                    {{ resetLabel }}
                </button>
            </div>
            <div v-if="!disabled" class="flex flex-wrap gap-2" :aria-label="t('invoices.document.placeholders')">
                <button
                    v-for="token in TOKENS"
                    :key="token"
                    type="button"
                    class="num flex min-h-10 items-center rounded-control border border-line-input bg-surface px-3 text-13"
                    @click="insertToken(token)"
                >
                    {{ token }}
                </button>
            </div>
            <div class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3">
                <div class="flex items-baseline justify-between gap-2 text-12 text-ink-3">
                    <span>{{ t('invoices.document.example') }}</span>
                    <span v-if="currency && currency !== 'UAH'" class="num" :class="{ 'text-warning-text': warnings.includes('payment_purpose_too_long') }">
                        {{ [...preview].length }} / {{ REFERENCE_LENGTH }}
                    </span>
                </div>
                <span class="text-15 leading-[1.45] break-words">{{ preview }}</span>
            </div>
            <p v-if="warnings.includes('payment_purpose_too_long')" class="m-0 text-13 leading-normal text-warning-text" role="status">
                {{ t('invoices.document.tooLong', { length: REFERENCE_LENGTH }) }}
            </p>
            <p v-if="warnings.includes('payment_purpose_charset')" class="m-0 text-13 leading-normal text-warning-text" role="status">
                {{ t('invoices.document.charset') }}
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <div class="flex">
                <SwitchTile v-model="actEnabled" :label="t('invoices.document.actNote')" :class="{ 'pointer-events-none opacity-60': disabled }" />
            </div>
            <div class="flex items-center justify-between gap-2 text-12 text-ink-3">
                <span>{{ inheritedNote(inherited?.act_note_enabled.source, onOff(inherited?.act_note_enabled.value)) }}</span>
                <button v-if="!disabled && model.act_note_enabled !== null" type="button" class="min-h-9 text-13 font-medium text-accent" @click="set('act_note_enabled', null)">
                    {{ resetLabel }}
                </button>
            </div>
            <template v-if="actEnabled">
                <TextAreaField
                    v-model="actNote"
                    :label="t('invoices.document.actNoteText')"
                    :rows="3"
                    :placeholder="inheritedText('act_note')"
                    :disabled="disabled"
                    :error="errors.act_note"
                />
                <div class="flex items-center justify-between gap-2 text-12 text-ink-3">
                    <span>{{ model.act_note === null ? inheritedNote(inherited?.act_note.source) : '' }}</span>
                    <button
                        v-if="!disabled && model.act_note === null"
                        type="button"
                        class="min-h-9 text-13 font-medium text-accent"
                        @click="actNote = inheritedText('act_note')"
                    >
                        {{ t('invoices.document.edit') }}
                    </button>
                    <button v-else-if="!disabled" type="button" class="min-h-9 text-13 font-medium text-accent" @click="set('act_note', null)">
                        {{ resetLabel }}
                    </button>
                </div>
            </template>
        </div>

        <div class="flex flex-col gap-1.5">
            <div class="flex">
                <SwitchTile v-model="showContract" :label="t('invoices.document.showContract')" :class="{ 'pointer-events-none opacity-60': disabled }" />
            </div>
            <div class="flex items-center justify-between gap-2 text-12 text-ink-3">
                <span>{{ inheritedNote(inherited?.show_contract.source, onOff(inherited?.show_contract.value)) }}</span>
                <button v-if="!disabled && model.show_contract !== null" type="button" class="min-h-9 text-13 font-medium text-accent" @click="set('show_contract', null)">
                    {{ resetLabel }}
                </button>
            </div>
        </div>

        <p v-if="level !== 'invoice'" class="m-0 text-13 leading-normal text-ink-3">{{ t('invoices.document.changesDrafts') }}</p>
    </div>
</template>
