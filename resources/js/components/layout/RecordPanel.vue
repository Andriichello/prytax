<script setup lang="ts">
import { provide, ref } from 'vue';
import { useOverlay } from '@/composables/useOverlay';
import { t } from '@/i18n';

/**
 * The record panel of the desktop layout (D-RecordPanels, design.md §17.3): a panel on the right over the page it
 * was opened from, which stays in place under a scrim. ✕ (the page's header gives it), Escape and a click on the
 * scrim close it (`close`); the focus moves into it, the page behind is inert, and a sheet or a dialog opened in it
 * lies over it. 560 px wide, 760 px for the invoice editor (`wide`). The page inside renders its desktop header and
 * footer for a panel (`SubScreen`, which reads `inPanel`).
 */
const { wide = false } = defineProps<{ wide?: boolean }>();

const emit = defineEmits<{ close: [] }>();

const open = ref(true);
const panel = ref<HTMLElement | null>(null);

function close(): void {
    emit('close');
}

const { layer } = useOverlay(open, close, panel);

provide('inPanel', { close });
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 bg-[rgb(23_25_31/0.38)]" :style="{ zIndex: 40 + layer * 10 }" aria-hidden="true" @click="close" />
        <section
            ref="panel"
            role="dialog"
            aria-modal="true"
            :aria-label="t('shell.nav.recordPanel')"
            tabindex="-1"
            class="fixed inset-y-0 right-0 flex max-w-full flex-col bg-paper shadow-[-16px_0_40px_rgb(23_25_31/0.2)] outline-none"
            :class="wide ? 'w-190' : 'w-140'"
            :style="{ zIndex: 50 + layer * 10 }"
        >
            <slot />
        </section>
    </Teleport>
</template>
