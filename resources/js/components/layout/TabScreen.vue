<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import TabBar from '@/components/layout/TabBar.vue';
import TopHeader from '@/components/layout/TopHeader.vue';

/**
 * A root screen (Головна, Інвойси, Облік, Податки, Ще; design.md §16.2): the top bar scrolls away, while a block
 * with the title, its action (`title-action`), an optional subtitle, a mode switch (`switcher`) and the filter badges
 * (`filters`) sticks to the top, with a rule and a soft shadow once stuck. Then the content and the tab bar.
 */
const { title } = defineProps<{ title: string }>();

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
    <div class="flex min-h-dvh flex-col">
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
