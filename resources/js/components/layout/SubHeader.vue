<script setup lang="ts">
import { useRouter, type RouteLocationRaw } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * The header of a sub-page: a back arrow, the title (or, with `muted`, a context line above the page's own heading),
 * and an optional action on the right (the `action` slot); with `closable`, a ✕ on the right instead of the arrow,
 * for a step that is left rather than gone back from. Back returns to the previous page of the app, or goes to
 * `back` when the page was opened directly.
 */
const { title, back = { name: 'home' }, muted = false, subtitle = undefined, closable = false } = defineProps<{
    title: string;
    back?: RouteLocationRaw;
    muted?: boolean;
    /** A second line under the title, such as the client of a contract. */
    subtitle?: string;
    closable?: boolean;
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
    <header class="flex items-center gap-1 px-2 pt-2" :class="closable ? 'pl-4' : ''">
        <button v-if="!closable" type="button" :aria-label="t('shell.nav.back')" class="flex size-11 shrink-0 items-center justify-center text-ink" @click="goBack">
            <Icon name="back" :size="22" />
        </button>
        <span v-if="muted" class="min-w-0 flex-1 truncate text-15 text-ink-3">{{ title }}</span>
        <div v-else-if="subtitle" class="flex min-w-0 flex-1 flex-col">
            <h1 class="m-0 truncate text-17 font-semibold">{{ title }}</h1>
            <span class="truncate text-12 text-ink-3">{{ subtitle }}</span>
        </div>
        <h1 v-else class="m-0 min-w-0 flex-1 truncate text-17 font-semibold">{{ title }}</h1>
        <slot name="action" />
        <button v-if="closable" type="button" :aria-label="t('shell.ui.close')" class="flex size-11 shrink-0 items-center justify-center text-ink" @click="goBack">
            <Icon name="close" :size="22" />
        </button>
    </header>
</template>
