<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { currentQuarter, dateParts } from '@/utils/dates';

/**
 * The year of Облік and Податки: a small native select from the profile's first year (at most six back) to now.
 */
const year = defineModel<number>({ required: true });

const { profile } = useCurrentProfile();

const years = computed(() => {
    const now = currentQuarter().year;
    const created = profile.value?.created_at ? dateParts(profile.value.created_at).year : now;
    const registered = profile.value?.registered_at ? dateParts(profile.value.registered_at).year : now;
    const first = Math.max(now - 6, Math.min(created, registered, year.value));
    const list: number[] = [];

    for (let value = Math.max(now, year.value); value >= first; value--) {
        list.push(value);
    }

    return list;
});
</script>

<template>
    <label class="relative flex min-h-11 items-center rounded-control border border-line-input bg-surface">
        <span class="sr-only">Рік</span>
        <select
            v-model.number="year"
            class="num h-11 appearance-none bg-transparent pr-8 pl-3 text-15 font-medium"
        >
            <option v-for="value in years" :key="value" :value="value">{{ value }}</option>
        </select>
        <Icon name="chevronDown" :size="16" class="pointer-events-none absolute right-2.5" />
    </label>
</template>
