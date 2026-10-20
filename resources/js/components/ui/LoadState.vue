<script setup lang="ts">
import { computed } from 'vue';
import { ApiError } from '@/api/client';
import Icon from '@/components/ui/Icon.vue';

/**
 * What a screen shows instead of its content: a spinner while loading, or the error with "Спробувати ще раз".
 * Content renders in the default slot once loaded.
 */
const { loading, error } = defineProps<{
    loading: boolean;
    error: unknown;
}>();

const emit = defineEmits<{ retry: [] }>();

const message = computed(() => {
    if (error instanceof ApiError) {
        if (error.status === 404) {
            return 'Не знайдено: запис видалено, або він належить іншому ФОП.';
        }

        if (error.status === 403) {
            return 'Недостатньо прав, щоб це переглянути.';
        }
    }

    return 'Не вдалося завантажити дані. Перевірте з’єднання і спробуйте ще раз.';
});
</script>

<template>
    <div v-if="error" class="flex flex-col items-start gap-3 rounded-card border border-line bg-surface p-4" role="alert">
        <p class="m-0 text-14 text-ink-2">{{ message }}</p>
        <button
            type="button"
            class="flex min-h-11 items-center rounded-control border border-line-input bg-surface px-4 text-14 font-medium"
            @click="emit('retry')"
        >
            Спробувати ще раз
        </button>
    </div>
    <div v-else-if="loading" class="flex justify-center py-10 text-ink-3" role="status" aria-label="Завантаження">
        <Icon name="spinner" class="animate-spin" />
    </div>
    <slot v-else />
</template>
