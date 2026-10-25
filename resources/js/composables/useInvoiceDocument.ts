import { computed, type Ref } from 'vue';
import type {
    EffectiveInvoiceSettings,
    InvoiceDocumentSettings,
    InvoiceLocale,
    InvoiceStyle,
    InvoiceTexts,
    SettingSource,
} from '@/api/types';
import { LOCALE_NAMES, t } from '@/i18n';
import { type PurposeSample, purposeValues, purposeWarnings, REFERENCE_LENGTH, renderPurpose } from '@/utils/invoiceDocument';
import { DEFAULT_INVOICE_STYLE, styleName } from '@/utils/invoiceStyles';

/** The values, which the levels below a form's level give each document setting, with the level of each. */
export type InheritedDocument = {
    [K in keyof EffectiveInvoiceSettings]: { value: EffectiveInvoiceSettings[K]['value']; source: SettingSource };
};

/** What a form of the document settings knows besides its own values. */
export interface InvoiceDocumentContext {
    /** The values of the levels below this one. */
    inherited: InheritedDocument | null;
    /** The built-in texts in each language. */
    texts: Record<InvoiceLocale, InvoiceTexts> | null;
    /** The invoice the preview fills the payment purpose for. */
    sample: PurposeSample;
    /** The invoice's currency, in which a payment purpose is checked against SWIFT, unless it is hryvnias. */
    currency: string | null;
    level: 'invoice' | 'client' | 'profile';
}

/** The two notes, each with its switch and text, owned whole by the level, which sets the switch. */
export type NoteName = 'payment_note' | 'other_note';

/**
 * The document settings of an invoice, a client's invoices or the ФОП's (design.md §4.1, §16.3), as the accordion
 * forms show them: each setting's effective value, where it comes from («Типово», «Як у ФОП: English», «Власне»), the
 * texts as they print, and the changes, which keep a note whole at its level.
 *
 * @param model The level's own values, each null to take the next level's.
 * @param context What the form knows besides them.
 */
export function useInvoiceDocument(model: Ref<InvoiceDocumentSettings>, context: () => InvoiceDocumentContext) {
    const inherited = computed(() => context().inherited);

    const locale = computed<InvoiceLocale>(() => model.value.locale ?? inherited.value?.locale.value ?? 'en');

    /** «Як у клієнта», «Як у ФОП» or «Типово». */
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

    /** What the levels below give a setting: «Як у ФОП: English». */
    function inheritedNote(source: SettingSource | undefined, value?: string): string {
        return value ? t('invoices.document.inheritedValue', { source: sourceName(source), value }) : sourceName(source);
    }

    const onOff = (value: boolean | undefined): string => (value ? t('invoices.document.on') : t('invoices.document.off'));

    /** The inherited text of a setting; a built-in one in the form's language. */
    function inheritedText(key: 'payment_purpose_template' | 'act_note'): string {
        const setting = inherited.value?.[key];
        const texts = context().texts;

        if (setting?.source === 'default' && texts) {
            return texts[locale.value][key === 'act_note' ? 'act_note' : 'payment_purpose'];
        }

        return setting?.value ?? '';
    }

    /** Change some settings of the model. */
    function set(changes: Partial<InvoiceDocumentSettings>): void {
        model.value = { ...model.value, ...changes };
    }

    /* The style */
    const style = computed<InvoiceStyle>(() => model.value.style ?? inherited.value?.style.value ?? DEFAULT_INVOICE_STYLE);

    /** «Типово», «Як у ФОП»; an own one names the inherited one: «Власний · у ФОП: Outlined». */
    const styleSource = computed(() => {
        const below = inherited.value?.style;

        if (model.value.style === null) {
            return sourceName(context().level === 'profile' ? 'default' : below?.source);
        }

        if (context().level === 'profile') {
            return t('invoices.document.own');
        }

        const levelName = below?.source === 'client'
            ? t('invoices.document.levelClient')
            : below?.source === 'profile' ? t('invoices.document.levelProfile') : t('invoices.document.asDefault');

        return t('invoices.document.styleOwn', { level: levelName, name: styleName(below?.value) });
    });

    /* The language and the contract section */
    const localeInherited = computed(() => inheritedNote(
        inherited.value?.locale.source,
        inherited.value ? LOCALE_NAMES[inherited.value.locale.value] : undefined,
    ));
    const localeSource = computed(() => (model.value.locale === null ? localeInherited.value : t('invoices.document.own')));

    const showContract = computed(() => model.value.show_contract ?? inherited.value?.show_contract.value ?? true);
    const contractSource = computed(() => (model.value.show_contract === null
        ? inheritedNote(inherited.value?.show_contract.source, onOff(inherited.value?.show_contract.value))
        : t('invoices.document.own')));

    /* The payment purpose */
    const values = computed(() => purposeValues(context().sample, locale.value, context().texts?.[locale.value] ?? null));
    const purposeTemplate = computed(() => model.value.payment_purpose_template ?? inheritedText('payment_purpose_template'));
    const purpose = computed(() => renderPurpose(purposeTemplate.value, values.value));
    const purposeWarningCodes = computed(() => purposeWarnings(purpose.value, context().currency));
    const purposeMeta = computed(() => (context().currency && context().currency !== 'UAH'
        ? `${[...purpose.value].length} / ${REFERENCE_LENGTH}`
        : undefined));
    const purposeWarning = computed(() => {
        if (purposeWarningCodes.value.includes('payment_purpose_too_long')) {
            return t('invoices.document.tooLong', { length: REFERENCE_LENGTH });
        }

        return purposeWarningCodes.value.includes('payment_purpose_charset') ? t('invoices.document.charset') : null;
    });
    const purposeSource = computed(() => (model.value.payment_purpose_template === null
        ? sourceName(inherited.value?.payment_purpose_template.source)
        : t('invoices.document.own')));

    /** The purpose a template would print, for the editor's example. */
    const renderTemplate = (template: string): string => renderPurpose(template || inheritedText('payment_purpose_template'), values.value);

    /* The act note: a switch and a text of their own */
    const actEnabled = computed(() => model.value.act_note_enabled ?? inherited.value?.act_note_enabled.value ?? true);
    const actText = computed(() => model.value.act_note ?? inheritedText('act_note'));
    const actOwn = computed(() => model.value.act_note_enabled !== null || model.value.act_note !== null);
    const actSource = computed(() => (actOwn.value
        ? t('invoices.document.own')
        : inheritedNote(inherited.value?.act_note_enabled.source, onOff(inherited.value?.act_note_enabled.value))));

    /* The notes, each whole at the level, which sets its switch */
    function owns(name: NoteName): boolean {
        return model.value[`${name}_enabled`] !== null;
    }

    function noteEnabled(name: NoteName): boolean {
        return owns(name) ? model.value[`${name}_enabled`] === true : inherited.value?.[`${name}_enabled`].value ?? false;
    }

    function noteText(name: NoteName): string | null {
        return owns(name) ? model.value[name] : inherited.value?.[name].value ?? null;
    }

    /** «Типово: без примітки», «Як у клієнта», or «Власна» of the level's own note. */
    function noteSource(name: NoteName): string {
        if (owns(name)) {
            return t('invoices.document.own');
        }

        const below = inherited.value?.[`${name}_enabled`];

        return below?.value ? sourceName(below.source) : inheritedNote(below?.source, t('invoices.document.noNote'));
    }

    /** Switch a note on or off at this level: it becomes the level's own, with the text it shows. */
    function toggleNote(name: NoteName, value: boolean): void {
        set({ [`${name}_enabled`]: value, [name]: noteText(name) } as Partial<InvoiceDocumentSettings>);
    }

    /** Give a note its text at this level, switched on. */
    function writeNote(name: NoteName, text: string): void {
        set({ [`${name}_enabled`]: true, [name]: text === '' ? null : text } as Partial<InvoiceDocumentSettings>);
    }

    function resetNote(name: NoteName): void {
        set({ [`${name}_enabled`]: null, [name]: null } as Partial<InvoiceDocumentSettings>);
    }

    /* The closed groups' values */
    const noteState = (name: NoteName): string => {
        const text = noteText(name);

        return noteEnabled(name) && text ? `«${text}»` : t('invoices.document.noNoteShort');
    };

    return {
        locale,
        sourceName,
        inheritedText,
        set,
        style,
        styleSource,
        localeInherited,
        localeSource,
        showContract,
        contractSource,
        values,
        purpose,
        purposeMeta,
        purposeWarning,
        purposeWarningCodes,
        purposeSource,
        renderTemplate,
        actEnabled,
        actText,
        actOwn,
        actSource,
        owns,
        noteEnabled,
        noteText,
        noteSource,
        toggleNote,
        writeNote,
        resetNote,
        noteState,
    };
}
