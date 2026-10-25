<script setup lang="ts">
import { computed, ref } from 'vue';
import type { InvoiceDocumentSettings } from '@/api/types';
import PurposePlaceholderSheet from '@/components/invoice/PurposePlaceholderSheet.vue';
import TextPreviewCard from '@/components/invoice/TextPreviewCard.vue';
import Icon from '@/components/ui/Icon.vue';
import { type InvoiceDocumentContext, type NoteName, useInvoiceDocument } from '@/composables/useInvoiceDocument';
import { t } from '@/i18n';
import { insertAtCaret, REFERENCE_LENGTH, unknownPlaceholders } from '@/utils/invoiceDocument';

/**
 * The texts of an invoice's document, as an open accordion group edits them (design.md §16.3, «Тексти в інвойсі»):
 * the payment purpose, the act note, the payment note (under the payment details) and the other note (on the right,
 * under the act), each a card with the text as it prints. The purpose's editor inserts placeholders from a sheet and
 * shows an example; a note's editor allows 500 characters. Each note is whole at the level, which switches it.
 */
const { context, errors = {} } = defineProps<{
    context: InvoiceDocumentContext;
    errors?: Partial<Record<keyof InvoiceDocumentSettings, string | undefined>>;
}>();

const model = defineModel<InvoiceDocumentSettings>({ required: true });

const doc = useInvoiceDocument(model, () => context);

/* The payment purpose: placeholders go in at the caret of the draft being edited */
const placeholdersOpen = ref(false);
let insert: ((token: string) => void) | null = null;

function openPlaceholders(draft: string, caret: number | null, setDraft: (text: string, at: number | null) => void): void {
    insert = (token: string) => {
        const inserted = insertAtCaret(draft, caret, token);

        setDraft(inserted.text, inserted.caret);
    };
    placeholdersOpen.value = true;
}

const unknown = (template: string): string | undefined => {
    const names = unknownPlaceholders(template);

    return names.length
        ? t('invoices.document.unknownPlaceholders', { placeholders: names.map((name) => `{${name}}`).join(', ') })
        : undefined;
};

const purposeError = computed(() => unknown(model.value.payment_purpose_template ?? '') ?? errors.payment_purpose_template);

const notes = computed(() => ([
    { name: 'payment_note', title: t('invoices.document.paymentNoteTitle'), where: t('invoices.document.underDetails') },
    { name: 'other_note', title: t('invoices.document.otherNoteTitle'), where: t('invoices.document.underAct') },
] as { name: NoteName; title: string; where: string }[]));
</script>

<template>
    <div class="flex flex-col gap-3.5">
        <TextPreviewCard
            :title="t('invoices.document.paymentPurpose')"
            :source="doc.purposeSource.value"
            :preview="doc.purpose.value"
            :meta="doc.purposeMeta.value"
            :warning="doc.purposeWarning.value"
            :own="model.payment_purpose_template ?? ''"
            :placeholder="doc.inheritedText('payment_purpose_template')"
            :can-reset="model.payment_purpose_template !== null"
            :error="purposeError"
            @done="doc.set({ payment_purpose_template: $event === '' ? null : $event })"
            @reset="doc.set({ payment_purpose_template: null })"
        >
            <template #tools="{ draft, caret, setDraft }">
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-1.5 rounded-full border border-line-input bg-surface px-3 text-14 font-medium text-accent"
                        @click="openPlaceholders(draft, caret, setDraft)"
                    >
                        <Icon name="plus" :size="16" />
                        {{ t('invoices.document.placeholder') }}
                    </button>
                    <span v-if="context.currency && context.currency !== 'UAH'" class="num text-12 text-ink-3">
                        {{ [...doc.renderTemplate(draft)].length }} / {{ REFERENCE_LENGTH }}
                    </span>
                </div>
                <p v-if="unknown(draft)" class="m-0 text-12 text-negative">{{ unknown(draft) }}</p>
            </template>
            <template #example="{ draft }">
                <div class="flex flex-col gap-1 rounded-[10px] bg-muted px-3 py-2.5">
                    <span class="text-12 text-ink-3">{{ t('invoices.document.example') }}</span>
                    <span class="text-14 leading-[1.45] [overflow-wrap:anywhere]">{{ doc.renderTemplate(draft) }}</span>
                </div>
            </template>
        </TextPreviewCard>

        <TextPreviewCard
            :title="t('invoices.document.actNote')"
            :source="doc.actSource.value"
            :preview="doc.actText.value"
            toggle
            :enabled="doc.actEnabled.value"
            :own="model.act_note ?? ''"
            :placeholder="doc.inheritedText('act_note')"
            :hint="t('invoices.document.noteLimit')"
            :maxlength="500"
            :can-reset="doc.actOwn.value"
            :error="errors.act_note"
            @toggle="doc.set({ act_note_enabled: $event })"
            @done="doc.set({ act_note_enabled: true, act_note: $event === '' ? null : $event })"
            @reset="doc.set({ act_note_enabled: null, act_note: null })"
        />

        <TextPreviewCard
            v-for="note in notes"
            :key="note.name"
            :title="note.title"
            :where="note.where"
            :source="doc.noteSource(note.name)"
            :preview="doc.noteText(note.name)"
            toggle
            :enabled="doc.noteEnabled(note.name)"
            :own="doc.owns(note.name) ? model[note.name] ?? '' : ''"
            :placeholder="doc.owns(note.name) ? undefined : doc.noteText(note.name) ?? undefined"
            :hint="t('invoices.document.noteLimit')"
            :maxlength="500"
            :can-reset="doc.owns(note.name)"
            :error="errors[note.name]"
            @toggle="doc.toggleNote(note.name, $event)"
            @done="doc.writeNote(note.name, $event)"
            @reset="doc.resetNote(note.name)"
        />

        <PurposePlaceholderSheet v-model:open="placeholdersOpen" :values="doc.values.value" @insert="insert?.($event)" />
    </div>
</template>
