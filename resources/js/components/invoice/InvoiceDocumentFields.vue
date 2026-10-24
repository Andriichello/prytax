<script setup lang="ts">
import { computed, ref } from 'vue';
import type {
    EffectiveInvoiceSettings,
    InvoiceDocumentSettings,
    InvoiceLocale,
    InvoiceNoteKind,
    InvoiceStyle,
    InvoiceTexts,
    SettingSource,
} from '@/api/types';
import InvoiceStylePicker from '@/components/invoice/InvoiceStylePicker.vue';
import PurposePlaceholderSheet from '@/components/invoice/PurposePlaceholderSheet.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import Icon from '@/components/ui/Icon.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SwitchTile from '@/components/ui/SwitchTile.vue';
import { LOCALE_NAMES, t } from '@/i18n';
import {
    insertAtCaret,
    type PurposeSample,
    purposeValues,
    purposeWarnings,
    REFERENCE_LENGTH,
    renderPurpose,
    unknownPlaceholders,
} from '@/utils/invoiceDocument';
import { DEFAULT_INVOICE_STYLE, styleName, styleThumbnail } from '@/utils/invoiceStyles';

/**
 * The document settings of an invoice, of a client's invoices or of the ФОП's (design.md §4.1), in one order on every
 * level: the style (a row, which opens the picker), the language, the payment purpose (placeholders from a sheet, and
 * a preview of it on the invoice), the act note, the contract section and the invoice's note. Each is the level's
 * own, or empty to take the one below it, which shows beside it ("Як у ФОП: English") with a reset of the own one.
 * The note is whole: editing any of its switch, text or kind copies the inherited note to the level first. The
 * built-in texts follow the language chosen on the form. At the ФОП's level the language is its document language,
 * which is never empty.
 */
type Inherited = { [K in keyof EffectiveInvoiceSettings]: { value: EffectiveInvoiceSettings[K]['value']; source: SettingSource } };

const {
    inherited,
    texts,
    sample,
    currency,
    level,
    subtitle,
    target = {},
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
    /** What the style picker names under its title: the ФОП, the client, or "Інвойс BL-2026-10-31-14". */
    subtitle: string;
    /** The invoice, or the client whose latest invoice, the style preview renders. */
    target?: { client_id?: number | null; invoice_id?: number | null };
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

/* The style: a row with its thumbnail and where it comes from, which opens the picker */
const picking = ref(false);
const style = computed<InvoiceStyle>(() => model.value.style ?? inherited?.style.value ?? DEFAULT_INVOICE_STYLE);

/** "Типово", "Як у ФОП"; an own one names the inherited one: "Власний · ФОП: Outlined". */
const styleSource = computed(() => {
    const below = inherited?.style;

    if (model.value.style === null) {
        return sourceName(level === 'profile' ? 'default' : below?.source);
    }

    if (level === 'profile') {
        return '';
    }

    const levelName = below?.source === 'client'
        ? t('invoices.document.levelClient')
        : below?.source === 'profile' ? t('invoices.document.levelProfile') : t('invoices.document.asDefault');

    return t('invoices.document.styleOwn', { level: levelName, name: styleName(below?.value) });
});

/* The language */
const localeOptions = computed(() => (['en', 'uk'] as const).map((code) => ({ value: code, label: LOCALE_NAMES[code] })));
const localePlaceholder = computed(() => (localeRequired
    ? undefined
    : inheritedNote(inherited?.locale.source, inherited ? LOCALE_NAMES[inherited.locale.value] : undefined)));

/* The payment purpose: its template, the placeholders inserted at the caret, and the preview on the sample invoice */
const purposeTemplate = computed({
    get: () => model.value.payment_purpose_template ?? '',
    set: (value: string) => set('payment_purpose_template', value === '' ? null : value),
});

const caret = ref<number | null>(null);
const placeholdersOpen = ref(false);

function rememberCaret(event: Event): void {
    if (event.target instanceof HTMLTextAreaElement) {
        caret.value = event.target.selectionStart;
    }
}

function insertToken(token: string): void {
    const inserted = insertAtCaret(purposeTemplate.value, caret.value, token);

    purposeTemplate.value = inserted.text;
    caret.value = inserted.caret;
}

const values = computed(() => purposeValues(sample, locale.value, texts?.[locale.value] ?? null));
const preview = computed(() => renderPurpose(purposeTemplate.value || inheritedText('payment_purpose_template'), values.value));
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

/* The invoice's note: the level, which switches it, owns its text and kind too */
const ownsNote = computed(() => model.value.note_enabled !== null);

/** Make the inherited note the level's own, then change one of its fields. */
function setNote<K extends 'note_enabled' | 'note' | 'note_kind'>(key: K, value: InvoiceDocumentSettings[K]): void {
    const own = ownsNote.value
        ? model.value
        : {
            ...model.value,
            note_enabled: inherited?.note_enabled.value ?? false,
            note: inherited?.note.value ?? null,
            note_kind: inherited?.note_kind.value ?? 'other',
        };

    model.value = { ...own, [key]: value };
}

function resetNote(): void {
    model.value = { ...model.value, note_enabled: null, note: null, note_kind: null };
}

const noteEnabled = computed({
    get: () => (ownsNote.value ? model.value.note_enabled === true : inherited?.note_enabled.value ?? false),
    set: (value: boolean) => setNote('note_enabled', value),
});
const noteText = computed({
    get: () => (ownsNote.value ? model.value.note ?? '' : ''),
    set: (value: string) => setNote('note', value === '' ? null : value),
});
const noteKind = computed<InvoiceNoteKind>({
    get: () => (ownsNote.value ? model.value.note_kind ?? 'other' : inherited?.note_kind.value ?? 'other'),
    set: (value) => setNote('note_kind', value),
});

/** What the inherited note is: "без примітки", "примітка щодо оплати", "інша примітка". */
const inheritedNoteState = computed(() => {
    if (!inherited?.note_enabled.value) {
        return t('invoices.document.noNote');
    }

    return inherited.note_kind.value === 'payment' ? t('invoices.document.paymentNote') : t('invoices.document.otherNote');
});

const noteKindOptions = computed(() => [
    { value: 'payment' as const, label: t('invoices.document.notePayment') },
    { value: 'other' as const, label: t('invoices.document.noteOther') },
]);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">{{ t('invoices.document.style') }}</span>
            <button
                type="button"
                class="flex items-center gap-3.5 rounded-control border border-line-input bg-surface p-2.5 pr-3.5 text-left"
                :class="{ 'pointer-events-none': disabled }"
                :disabled="disabled"
                @click="picking = true"
            >
                <img
                    :src="styleThumbnail(style, locale)"
                    alt=""
                    class="h-15.5 w-11 shrink-0 rounded-[3px] border border-line bg-white object-cover"
                >
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="truncate text-17 font-medium text-ink">{{ styleName(style) }}</span>
                    <span v-if="styleSource" class="truncate text-13 text-ink-3">{{ styleSource }}</span>
                </span>
                <Icon v-if="!disabled" name="chevronRight" :size="20" class="shrink-0 text-ink-2" />
            </button>
            <div v-if="!disabled && model.style !== null" class="flex justify-end">
                <button type="button" class="min-h-9 text-13 font-medium text-accent" @click="set('style', null)">{{ resetLabel }}</button>
            </div>
        </div>

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
                autogrow
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
            <button
                v-if="!disabled"
                type="button"
                class="flex min-h-10 items-center gap-1.5 self-start rounded-full border border-line-input bg-surface px-3.5 text-14 font-medium text-accent"
                @click="placeholdersOpen = true"
            >
                <Icon name="plus" :size="16" />
                {{ t('invoices.document.placeholder') }}
            </button>
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
                    autogrow
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

        <div class="flex flex-col gap-1.5">
            <div class="flex">
                <SwitchTile v-model="noteEnabled" :label="t('invoices.document.note')" :class="{ 'pointer-events-none opacity-60': disabled }" />
            </div>
            <div class="flex items-center justify-between gap-2 text-12 text-ink-3">
                <span>{{ ownsNote ? '' : inheritedNote(inherited?.note_enabled.source, inheritedNoteState) }}</span>
                <button v-if="!disabled && ownsNote" type="button" class="min-h-9 text-13 font-medium text-accent" @click="resetNote">
                    {{ resetLabel }}
                </button>
            </div>
            <template v-if="noteEnabled">
                <TextAreaField
                    v-model="noteText"
                    :label="t('invoices.document.noteText')"
                    autogrow
                    :maxlength="500"
                    :placeholder="ownsNote ? undefined : inherited?.note.value ?? undefined"
                    :hint="t('invoices.document.noteHint')"
                    :disabled="disabled"
                    :error="errors.note"
                />
                <span class="text-13 font-medium text-ink-2">{{ t('invoices.document.noteWhere') }}</span>
                <SegmentedControl v-model="noteKind" :options="noteKindOptions" :label="t('invoices.document.noteWhere')" :disabled="disabled" />
                <p class="m-0 text-12 leading-normal text-ink-3">{{ t('invoices.document.noteWhereHint') }}</p>
            </template>
        </div>

        <p v-if="level !== 'invoice'" class="m-0 text-13 leading-normal text-ink-3">{{ t('invoices.document.changesDrafts') }}</p>

        <InvoiceStylePicker
            v-model:open="picking"
            :level="level"
            :subtitle="subtitle"
            :locale="locale"
            :current="model.style"
            :inherited="inherited?.style ?? null"
            :target="target"
            @choose="set('style', $event)"
        />
        <PurposePlaceholderSheet v-model:open="placeholdersOpen" :values="values" @insert="insertToken" />
    </div>
</template>
