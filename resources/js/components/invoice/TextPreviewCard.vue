<script setup lang="ts">
import { ref, watch } from 'vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue';
import { t } from '@/i18n';

/**
 * A text of the invoice (design.md §16.3): the payment purpose, the act note or a note. It shows where its value comes
 * from, and «Так буде в інвойсі» with the text as it prints. A card with a switch shows the text only while it is on,
 * and switching it on opens the editor at once. «Змінити» opens the editor: a growing textarea, whose placeholder is
 * the inherited text, then [Скинути] (while the level has its own text) … [Скасувати] [Готово]. Готово gives the text
 * to the group's draft (`done`); the group's Зберегти saves it.
 */
const {
    title,
    source,
    where = undefined,
    preview,
    meta = undefined,
    warning = null,
    toggle = false,
    enabled = true,
    own,
    placeholder = '',
    hint = undefined,
    maxlength = undefined,
    canReset = false,
    disabled = false,
    error = undefined,
} = defineProps<{
    title: string;
    /** Where the value comes from: «Типово», «Як у ФОП», «Власне». */
    source: string;
    /** Where the text prints, before the source: «Під реквізитами». */
    where?: string;
    /** The text as it prints, or null when there is none. */
    preview: string | null;
    /** Beside «Так буде в інвойсі», such as `74 / 140`. */
    meta?: string;
    warning?: string | null;
    /** Whether the card has a switch. */
    toggle?: boolean;
    enabled?: boolean;
    /** The level's own text, which the editor starts with ('' when it inherits). */
    own: string;
    /** The inherited text, shown in the empty editor. */
    placeholder?: string;
    hint?: string;
    maxlength?: number;
    /** Whether the level has its own value, which «Скинути» drops. */
    canReset?: boolean;
    disabled?: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    /** The switch was turned. */
    toggle: [value: boolean];
    /** The edited text: '' to take the inherited one. */
    done: [text: string];
    reset: [];
    /** The draft text, while it is edited, for a live example. */
    input: [text: string];
}>();

const editing = ref(false);
const draft = ref('');
/** Where the caret was in the draft, for a placeholder inserted from a sheet. */
const caret = ref<number | null>(null);

function rememberCaret(event: Event): void {
    if (event.target instanceof HTMLTextAreaElement) {
        caret.value = event.target.selectionStart;
    }
}

function edit(): void {
    draft.value = own;
    caret.value = null;
    editing.value = true;
}

function turn(value: boolean): void {
    emit('toggle', value);

    // A switched-on text is edited at once
    if (value) {
        edit();
    } else {
        editing.value = false;
    }
}

function done(): void {
    emit('done', draft.value.trim() === '' ? '' : draft.value);
    editing.value = false;
}

function reset(): void {
    emit('reset');
    editing.value = false;
}

watch(draft, (text) => emit('input', text));
</script>

<template>
    <section class="flex flex-col gap-2.5 rounded-control border border-line bg-surface px-3.5 py-3">
        <div class="flex items-center justify-between gap-3">
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-15 font-medium">{{ title }}</span>
                <span class="text-12 text-ink-3">{{ where ? `${where} · ${source}` : source }}</span>
            </span>
            <ToggleSwitch v-if="toggle" :model-value="enabled" :label="title" :disabled="disabled" @update:model-value="turn" />
            <button
                v-else-if="!editing && !disabled"
                type="button"
                class="min-h-8 shrink-0 text-14 font-medium text-accent"
                @click="edit"
            >
                {{ t('core.common.edit') }}
            </button>
        </div>

        <div v-if="editing" class="flex flex-col gap-2.5 border-t border-divider pt-2.5">
            <div @keyup="rememberCaret" @click="rememberCaret" @input="rememberCaret">
                <TextAreaField
                    v-model="draft"
                    :label="title"
                    hide-label
                    autogrow
                    :placeholder="placeholder"
                    :maxlength="maxlength"
                    :hint="hint"
                    :error="error"
                />
            </div>
            <slot name="tools" :draft="draft" :caret="caret" :set-draft="(text: string, at: number | null) => { draft = text; caret = at; }" />
            <slot name="example" :draft="draft" />
            <div class="flex items-center gap-2">
                <button v-if="canReset" type="button" class="h-10 px-1 text-15 font-medium text-accent" @click="reset">
                    {{ t('invoices.document.reset') }}
                </button>
                <span class="flex-1" />
                <button type="button" class="h-10 rounded-control border border-line-input bg-surface px-3.5 text-15 font-medium" @click="editing = false">
                    {{ t('core.common.cancel') }}
                </button>
                <button type="button" class="h-10 rounded-control bg-accent px-4 text-15 font-semibold text-white" @click="done">
                    {{ t('core.common.done') }}
                </button>
            </div>
        </div>

        <div v-else-if="preview !== null && (!toggle || enabled)" class="flex flex-col gap-1.5 border-t border-divider pt-2.5">
            <div class="flex justify-between gap-2 text-12 text-ink-3">
                <span>{{ t('invoices.document.willPrint') }}</span>
                <span v-if="meta" class="num">{{ meta }}</span>
            </div>
            <span class="text-15 leading-[1.45] whitespace-pre-line [overflow-wrap:anywhere]">{{ preview }}</span>
            <p v-if="warning" class="m-0 text-13 leading-normal text-warning-text" role="status">{{ warning }}</p>
            <div v-if="toggle && !disabled" class="flex justify-end">
                <button type="button" class="min-h-8 text-14 font-medium text-accent" @click="edit">{{ t('core.common.edit') }}</button>
            </div>
        </div>
    </section>
</template>
