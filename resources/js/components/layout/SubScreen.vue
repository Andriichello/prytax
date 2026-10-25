<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import SubHeader from '@/components/layout/SubHeader.vue';

/**
 * A sub-page: the back header, the content, and an optional sticky footer (the `footer` slot) for a form's primary
 * button. With a `heading`, the header shows `title` as a context line and the page starts with a large heading. With
 * a `toolbar` (such as the filter chips of Сповіщення), the header and the toolbar stick to the top together.
 */
const { title, back, heading, muted = false, subtitle = undefined } = defineProps<{
    title: string;
    back?: RouteLocationRaw;
    heading?: string;
    /** Show the title as a context line, for pages that set their own heading in the content. */
    muted?: boolean;
    subtitle?: string;
}>();
</script>

<template>
    <div class="flex min-h-dvh flex-col">
        <div :class="$slots.toolbar ? 'sticky top-0 z-10 flex flex-col gap-2 bg-paper pb-3' : ''">
            <SubHeader :title="title" :back="back" :muted="muted || !!heading" :subtitle="subtitle">
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
