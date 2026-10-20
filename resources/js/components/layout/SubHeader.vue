<script setup lang="ts">
import { useRouter, type RouteLocationRaw } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';

/**
 * The header of a sub-page: a back arrow, the title (or, with `muted`, a context line above the page's own heading),
 * and an optional action on the right (the `action` slot). Back returns to the previous page of the app, or goes to
 * `back` when the page was opened directly.
 */
const { title, back = { name: 'home' }, muted = false } = defineProps<{
    title: string;
    back?: RouteLocationRaw;
    muted?: boolean;
}>();

const router = useRouter();

function goBack(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        void router.replace(back);
    }
}
</script>

<template>
    <header class="flex items-center gap-1 px-2 pt-2">
        <button type="button" aria-label="Назад" class="flex size-11 shrink-0 items-center justify-center text-ink" @click="goBack">
            <Icon name="back" :size="22" />
        </button>
        <span v-if="muted" class="min-w-0 flex-1 truncate text-15 text-ink-3">{{ title }}</span>
        <h1 v-else class="m-0 min-w-0 flex-1 truncate text-17 font-semibold">{{ title }}</h1>
        <slot name="action" />
    </header>
</template>
