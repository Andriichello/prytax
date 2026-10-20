<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import SubHeader from '@/components/layout/SubHeader.vue';

/**
 * A sub-page: the back header, the content, and an optional sticky footer (the `footer` slot) for a form's primary
 * button. With a `heading`, the header shows `title` as a context line and the page starts with a large heading.
 */
const { title, back, heading } = defineProps<{
    title: string;
    back?: RouteLocationRaw;
    heading?: string;
}>();
</script>

<template>
    <div class="flex min-h-dvh flex-col">
        <SubHeader :title="title" :back="back" :muted="!!heading">
            <template #action>
                <slot name="action" />
            </template>
        </SubHeader>
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
