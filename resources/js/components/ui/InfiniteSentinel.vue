<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';

/**
 * The bottom of an infinite list: asks for more when it nears the screen, and shows the loading line meanwhile.
 */
const { loading = false } = defineProps<{ loading?: boolean }>();
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
            <slot>Завантаження…</slot>
        </template>
    </div>
</template>
