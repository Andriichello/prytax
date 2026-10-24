<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * The bottom of an infinite list: asks for more when it nears the screen, and shows the loading line meanwhile, or
 * the failure of the last page with a retry.
 */
const { loading = false, failed = false } = defineProps<{ loading?: boolean; failed?: boolean }>();
const emit = defineEmits<{ visible: [] }>();

const element = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            emit('visible');
        }
    }, { rootMargin: '400px 0px' });

    if (element.value) {
        observer.observe(element.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="element" role="status" class="flex min-h-14 items-center justify-center gap-2.5 text-14 text-ink-3">
        <template v-if="loading">
            <Icon name="spinner" :size="20" class="animate-spin text-accent" />
            <slot>{{ t('core.common.loading') }}</slot>
        </template>
        <template v-else-if="failed">
            <span>{{ t('shell.ui.loadMoreFailed') }}</span>
            <button type="button" class="min-h-11 px-1 text-14 font-medium text-accent" @click="emit('visible')">{{ t('core.common.retry') }}</button>
        </template>
    </div>
</template>
