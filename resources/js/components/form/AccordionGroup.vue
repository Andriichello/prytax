<script setup lang="ts">
import { computed } from 'vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useAccordion } from '@/composables/useAccordion';
import { t } from '@/i18n';

/**
 * A group of a form's fields (design.md §16.3): closed, a card with its title, «Змінити» and the values read-only (the
 * default slot, laid out by ReadRow); open, the same card with an accent border, «Редагування», the fields (`edit`)
 * and [Скасувати] [Зберегти]. One group is open at a time: the others dim and go inert. Opening, cancelling and saving
 * are the page's: it fills the group's draft on `open`, restores it on `cancel` and sends only its fields on `save`,
 * closing the group (`close()` of the accordion) once saved. An error keeps it open.
 */
const { id, title, editable = true, saving = false, error = null, rows = true } = defineProps<{
    id: string;
    title: string;
    /** Whether the role may change it: without it, the card has no «Змінити». */
    editable?: boolean;
    saving?: boolean;
    /** An error of the group, which isn't a field's, such as a failed request. */
    error?: string | null;
    /** Lay the read-only values out as rows of a label and a value. */
    rows?: boolean;
}>();

const emit = defineEmits<{ open: []; cancel: []; save: [] }>();

const { open } = useAccordion();

const isOpen = computed(() => open.value === id);
const dimmed = computed(() => open.value !== null && !isOpen.value);

function start(): void {
    open.value = id;
    emit('open');
}

function cancel(): void {
    open.value = null;
    emit('cancel');
}
</script>

<template>
    <section
        :aria-label="title"
        class="flex flex-col rounded-card bg-surface transition-opacity"
        :class="[
            isOpen
                ? 'gap-3.5 border-2 border-accent px-[15px] pt-2 pb-[15px] shadow-[0_6px_18px_rgb(43_69_184/0.12)]'
                : 'gap-2 border border-line px-4 pt-2 pb-3.5',
            dimmed ? 'pointer-events-none opacity-45' : '',
        ]"
        :inert="dimmed || undefined"
    >
        <div class="flex min-h-10 items-center justify-between gap-3">
            <h2 class="m-0 text-16 font-semibold">{{ title }}</h2>
            <span v-if="isOpen" class="rounded-badge bg-accent-soft px-2 py-[3px] text-12 font-semibold text-accent-hover">
                {{ t('core.accordion.editing') }}
            </span>
            <button
                v-else-if="editable"
                type="button"
                class="min-h-9 text-14 font-medium text-accent"
                @click="start"
            >
                {{ t('core.common.edit') }}
            </button>
        </div>

        <form v-if="isOpen" class="flex flex-col gap-3.5" novalidate @submit.prevent="emit('save')">
            <slot name="edit" />
            <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ error }}</p>
            <div class="flex gap-2.5 pt-1">
                <SecondaryButton class="flex-1" type="button" :disabled="saving" @click="cancel">{{ t('core.common.cancel') }}</SecondaryButton>
                <PrimaryButton class="flex-[1.4]" type="submit" :busy="saving">{{ t('core.common.save') }}</PrimaryButton>
            </div>
        </form>
        <div v-else-if="rows" class="grid grid-cols-[118px_minmax(0,1fr)] items-start gap-x-3 gap-y-2">
            <slot />
        </div>
        <slot v-else />
    </section>
</template>
