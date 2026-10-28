<script setup lang="ts">
import { computed, inject } from 'vue';
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router';
import BarActions from '@/components/layout/BarActions.vue';
import SubHeader from '@/components/layout/SubHeader.vue';
import Icon from '@/components/ui/Icon.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { t } from '@/i18n';

/**
 * A sub-page: the back header, the content, and an optional sticky footer (the `footer` slot) for a form's primary
 * button. With a `heading`, the header shows `title` as a context line and the page starts with a large heading. With
 * a `toolbar` (such as the filter chips of Сповіщення), the header and the toolbar stick to the top together.
 * On desktop (design.md §17.2–§17.3) a page gets the page header (a round back button, unless it is a sidebar's page
 * — `meta.section` —, the title, the action and the 📖 | 🔔 capsule) over a column of `width`: 760 px for settings
 * and records, 880 px for lists, or the whole page. In the record panel it gets the panel's header (← back to the
 * record it came from, the kind of record over the title, the action, ✕), a scrolling body and the footer.
 */
const { title, back, heading, muted = false, subtitle = undefined, closable = false, width = 'column' } = defineProps<{
    title: string;
    back?: RouteLocationRaw;
    heading?: string;
    /** Show the title as a context line, for pages that set their own heading in the content. */
    muted?: boolean;
    subtitle?: string;
    /** A ✕ on the right instead of the back arrow. */
    closable?: boolean;
    /** The desktop column: 760 px, 880 px (lists) or the whole width. */
    width?: 'column' | 'list' | 'full';
}>();

const route = useRoute();
const router = useRouter();
const { isDesktop } = useBreakpoint();
const panel = inject<{ close: () => void } | null>('inPanel', null);

/** In the panel, ← goes back to the record this one was opened from. */
const backInPanel = computed(() => {
    const previous = window.history.state?.back;

    return typeof previous === 'string' && Boolean(router.resolve(previous).meta.panel);
});

function goBack(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        void router.replace(back ?? { name: 'home' });
    }
}

const column = computed(() => ({ column: 'max-w-190', list: 'max-w-220', full: '' })[width]);
</script>

<template>
    <div v-if="panel" class="flex min-h-0 flex-1 flex-col">
        <header class="flex shrink-0 items-start gap-2 border-b border-line bg-surface py-4 pr-4 pl-6">
            <button
                v-if="backInPanel"
                type="button"
                :aria-label="t('shell.nav.back')"
                class="-ml-2 flex size-10 shrink-0 items-center justify-center text-ink"
                @click="router.back()"
            >
                <Icon name="back" :size="20" />
            </button>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span v-if="heading || muted" class="truncate text-12 text-ink-3">{{ title }}</span>
                <h2 v-if="heading || !muted" class="m-0 truncate text-22 font-semibold tracking-[-0.01em]">{{ heading ?? title }}</h2>
                <span v-if="subtitle" class="truncate text-13 text-ink-3">{{ subtitle }}</span>
            </div>
            <slot name="action" />
            <button type="button" :aria-label="t('shell.ui.close')" class="flex size-10 shrink-0 items-center justify-center text-ink" @click="panel.close()">
                <Icon name="close" :size="20" />
            </button>
        </header>
        <div v-if="$slots.toolbar" class="shrink-0 px-6 pt-4">
            <slot name="toolbar" />
        </div>
        <main class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5 *:shrink-0">
            <slot />
        </main>
        <footer v-if="$slots.footer" class="shrink-0 border-t border-line bg-surface px-6 py-3">
            <slot name="footer" />
        </footer>
    </div>

    <div v-else-if="isDesktop" class="flex min-h-dvh flex-col">
        <header class="flex items-start gap-4 px-10 pt-7 pb-5">
            <button
                v-if="!route.meta.section && !closable"
                type="button"
                :aria-label="t('shell.nav.back')"
                class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full border border-line-input bg-surface text-ink"
                @click="goBack"
            >
                <Icon name="back" :size="20" />
            </button>
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <h1 v-if="heading || (title && !muted)" class="m-0 truncate text-30 font-semibold tracking-[-0.02em]">{{ heading ?? title }}</h1>
                <span v-if="subtitle" class="truncate text-14 text-ink-3">{{ subtitle }}</span>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <slot name="action" />
                <BarActions />
            </div>
        </header>
        <div v-if="$slots.toolbar" class="w-full px-10 pb-4" :class="column">
            <slot name="toolbar" />
        </div>
        <main class="flex w-full flex-1 flex-col gap-5 px-10 pb-12" :class="column">
            <slot />
        </main>
        <footer v-if="$slots.footer" class="sticky bottom-0 z-10 border-t border-line bg-surface px-10 py-3">
            <div :class="column"><slot name="footer" /></div>
        </footer>
    </div>

    <div v-else class="flex min-h-dvh flex-col">
        <div :class="$slots.toolbar ? 'sticky top-0 z-10 flex flex-col gap-2 bg-paper pb-3' : ''">
            <SubHeader :title="title" :back="back" :muted="muted || !!heading" :subtitle="subtitle" :closable="closable">
                <template #action>
                    <slot name="action" />
                </template>
            </SubHeader>
            <div v-if="$slots.toolbar" class="px-4">
                <slot name="toolbar" />
            </div>
        </div>
        <main class="flex flex-1 flex-col gap-4 px-4 pb-8" :class="heading ? 'pt-2' : 'pt-3'">
            <h1 v-if="heading" class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ heading }}</h1>
            <slot />
        </main>
        <footer
            v-if="$slots.footer"
            class="sticky bottom-0 z-10 border-t border-line bg-surface px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+12px)]"
        >
            <slot name="footer" />
        </footer>
    </div>
</template>
