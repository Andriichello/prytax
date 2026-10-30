<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router';
import BarActions from '@/components/layout/BarActions.vue';
import SubHeader from '@/components/layout/SubHeader.vue';
import EditLink from '@/components/ui/EditLink.vue';
import Icon from '@/components/ui/Icon.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { usePanel } from '@/composables/usePanel';
import { t } from '@/i18n';
import { panelBackground } from '@/router';

/**
 * A sub-page: the back header, the content, and an optional sticky footer (the `footer` slot) for a form's primary
 * button. With a `heading`, the header shows `title` as a context line and the page starts with a large heading. With
 * a `toolbar` (such as the filter chips of Сповіщення), the header and the toolbar stick to the top together.
 * On desktop (design.md §17.2–§17.3) a page gets the page header (a round back button, unless it is a sidebar's page
 * — `meta.section` —, with the kind of record beside it when `title` is a context line, the title, the action and the
 * 📖 | 🔔 capsule) over the content, which takes the page's whole width (the app is at most 1200 px wide; the owner,
 * 2026-10-30), with the `aside` slot (the side note «Як це працює») at its end, never beside it. The page's primary
 * action (`NewPill`) is the `action`, left of the
 * capsule; the title keeps its width, the sub-line wraps, and the actions go under the title when they don't fit. In
 * the record panel it gets the panel's header (← back to the record it came from; the kind of record, `title`, over
 * the record's own title, `record`, with the `sub` line and the `badges` under it; «✎ Змінити» to `edit` when the
 * record can be edited; the action, ⋯; ✕), a scrolling body and the footer. `record`, `sub`, `badges` and `edit` are
 * the panel's only: a page shows the record's heading in its content.
 */
const { title, back, heading, muted = false, subtitle = undefined, closable = false, record = undefined, edit = undefined } = defineProps<{
    title: string;
    back?: RouteLocationRaw;
    heading?: string;
    /** Show the title as a context line, for pages that set their own heading in the content. */
    muted?: boolean;
    subtitle?: string;
    /** A ✕ on the right instead of the back arrow. */
    closable?: boolean;
    /** The record's title in the panel's header: in mono for a number or an amount, struck through when cancelled. */
    record?: { title: string; mono?: boolean; struck?: boolean };
    /** Where «✎ Змінити» of the panel's header leads, when the record can be edited. */
    edit?: RouteLocationRaw;
}>();

const route = useRoute();
const router = useRouter();
const { isDesktop, isCompact } = useBreakpoint();
const panel = usePanel();

/** The page's route: under the record panel, the page the panel was opened over (the current route is the panel's). */
const pageMeta = computed(() => (!panel && route.meta.panel && panelBackground.value ? router.resolve(panelBackground.value).meta : route.meta));

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

/** The page's sides: 40 px, or 24 px while the sidebar is a rail (below 1024 px). */
const gutter = computed(() => (isCompact.value ? 'px-6' : 'px-10'));
</script>

<template>
    <div v-if="panel" class="flex min-h-0 flex-1 flex-col">
        <header class="flex shrink-0 items-start gap-1.5 border-b border-line bg-surface pt-4 pr-3 pb-3.5 pl-6">
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
                <span v-if="heading || muted || record" class="truncate text-12 text-ink-3">{{ title }}</span>
                <h2 v-if="record" class="m-0 text-22 font-bold" :class="[record.mono ? 'num truncate' : 'tracking-[-0.01em] text-balance', record.struck ? 'line-through' : '']">
                    {{ record.title }}
                </h2>
                <h2 v-else-if="heading || !muted" class="m-0 truncate text-22 font-semibold tracking-[-0.01em]">{{ heading ?? title }}</h2>
                <span v-if="$slots.sub || subtitle" class="truncate text-14 text-ink-2"><slot name="sub">{{ subtitle }}</slot></span>
                <div v-if="$slots.badges" class="mt-1 flex flex-wrap items-center gap-1.5">
                    <slot name="badges" />
                </div>
            </div>
            <EditLink v-if="edit" :to="edit" class="mr-1 ml-2" />
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
        <header class="flex flex-wrap items-start gap-4 pt-7 pb-5" :class="gutter">
            <div v-if="!pageMeta.section && !closable" class="flex shrink-0 items-center gap-3">
                <button
                    type="button"
                    :aria-label="t('shell.nav.back')"
                    class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full border border-line-input bg-surface text-ink"
                    @click="goBack"
                >
                    <Icon name="back" :size="20" />
                </button>
                <span v-if="muted && title && !heading" class="mt-0.5 text-15 font-medium text-ink-2">{{ title }}</span>
            </div>
            <div class="flex max-w-full min-w-min flex-1 flex-col gap-1">
                <h1 v-if="heading || (title && !muted)" class="m-0 truncate text-30 font-semibold tracking-[-0.02em]">{{ heading ?? title }}</h1>
                <span v-if="subtitle" class="text-14 leading-[1.45] text-ink-3">{{ subtitle }}</span>
            </div>
            <div class="ml-auto flex shrink-0 items-center gap-3">
                <slot name="action" />
                <BarActions />
            </div>
        </header>
        <div v-if="$slots.toolbar" class="pb-4" :class="gutter">
            <slot name="toolbar" />
        </div>
        <div class="flex-1 pb-12" :class="gutter">
            <div class="flex w-full flex-col gap-6">
                <main class="flex w-full min-w-0 flex-col gap-5">
                    <slot />
                </main>
                <aside v-if="$slots.aside" class="flex w-full flex-col gap-2 rounded-card bg-muted p-4">
                    <slot name="aside" />
                </aside>
            </div>
        </div>
        <footer v-if="$slots.footer" class="sticky bottom-0 z-10 border-t border-line bg-surface py-3" :class="gutter">
            <slot name="footer" />
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
