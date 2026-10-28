<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import BarActions from '@/components/layout/BarActions.vue';
import TabBar from '@/components/layout/TabBar.vue';
import TopHeader from '@/components/layout/TopHeader.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';

/**
 * A root screen (Головна, Інвойси, Облік, Податки, Ще; design.md §16.2): the top bar scrolls away, while a block
 * with the title, its action (`title-action`), an optional subtitle, a mode switch (`switcher`) and the filter badges
 * (`filters`) sticks to the top, with a rule and a soft shadow once stuck. Then the content and the tab bar. On
 * desktop (§17.2) the page header holds the title with its sub-line on the left and the action with the 📖 | 🔔
 * capsule on the right (under the title, when they don't fit beside it); the switch and the filters follow it, and
 * the sidebar replaces the tab bar.
 */
const { title } = defineProps<{ title: string }>();

const { isDesktop } = useBreakpoint();

const sentinel = ref<HTMLElement | null>(null);
const stuck = ref(false);

let observer: IntersectionObserver | null = null;

onMounted(() => {
    if (!sentinel.value || typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer = new IntersectionObserver(([entry]) => {
        stuck.value = !entry.isIntersecting;
    });
    observer.observe(sentinel.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div v-if="isDesktop" class="flex min-h-dvh flex-col">
        <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4 px-10 pt-7 pb-5">
            <div class="flex max-w-full min-w-min flex-1 flex-col gap-1">
                <h1 class="m-0 text-30 font-semibold tracking-[-0.02em]">{{ title }}</h1>
                <slot name="subtitle" />
            </div>
            <div class="ml-auto flex shrink-0 items-center gap-3">
                <slot name="title-action" />
                <BarActions />
            </div>
        </header>
        <div v-if="$slots.switcher || $slots.filters" class="flex flex-col gap-3.5 px-10 pb-5">
            <slot name="switcher" />
            <slot name="filters" />
        </div>
        <main class="flex flex-1 flex-col gap-5 px-10 pb-12">
            <slot />
        </main>
    </div>
    <div v-else class="flex min-h-dvh flex-col">
        <TopHeader />
        <div ref="sentinel" aria-hidden="true" class="h-px" />
        <div
            class="sticky top-0 z-10 flex flex-col gap-3.5 bg-paper px-4 pb-3"
            :class="stuck ? 'pt-2 shadow-[0_1px_0_var(--color-line),0_6px_12px_-8px_rgb(23_25_31/0.25)]' : 'pt-3'"
        >
            <div class="flex flex-col gap-0.5">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ title }}</h1>
                    <slot name="title-action" />
                </div>
                <slot name="subtitle" />
            </div>
            <slot name="switcher" />
            <slot name="filters" />
        </div>
        <main class="flex flex-1 flex-col gap-4 px-4 pt-0.5 pb-6">
            <slot />
        </main>
        <TabBar />
    </div>
</template>
