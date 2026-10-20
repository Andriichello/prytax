<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, PracticeOverview } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import OverviewCard from '@/components/overview/OverviewCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PeriodStepper from '@/components/ui/PeriodStepper.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { currentQuarter } from '@/utils/dates';
import { countOf } from '@/utils/plural';

/**
 * Огляд практики: every ФОП of the organization for one quarter, stepped like a calendar up to the current one.
 * Profiles inactive in the quarter are hidden until asked for.
 */
const QUARTERS = ['I', 'II', 'III', 'IV'];

const route = useRoute();
const router = useRouter();
const { profiles, profileId, select } = useCurrentProfile();

const now = currentQuarter();
const year = computed(() => Number(route.query.year) || now.year);
const quarter = computed(() => {
    const value = Number(route.query.quarter);

    return value >= 1 && value <= 4 ? value : now.quarter;
});
const withInactive = computed(() => route.query.inactive === '1');

const { data, error, loading, reload } = useLoader(async (signal) => {
    const { data: overview } = await api.get<ApiResponse<PracticeOverview>>(
        `overview/${year.value}/${quarter.value}`,
        { with_inactive: withInactive.value || undefined },
        signal,
    );

    return overview;
}, [year, quarter, withInactive]);

const isCurrent = computed(() => year.value === now.year && quarter.value === now.quarter);
const activeCount = computed(() => profiles.value.filter((profile) => profile.is_active).length);
const hiddenCount = computed(() => (withInactive.value || !data.value ? 0 : profiles.value.length - data.value.profiles.length));

function step(by: number): void {
    const index = year.value * 4 + (quarter.value - 1) + by;

    void router.replace({ query: { ...route.query, year: Math.floor(index / 4), quarter: (index % 4) + 1 } });
}

async function open(id: number, target: 'home' | 'alerts'): Promise<void> {
    await select(id);
    await router.push({ name: target });
}
</script>

<template>
    <SubScreen :title="`Практика · ${countOf(activeCount, ['активний ФОП', 'активні ФОП', 'активних ФОП'])}`" heading="Огляд практики">
        <PeriodStepper
            :label="`${QUARTERS[quarter - 1]} квартал ${year}`"
            :can-next="!isCurrent"
            previous-label="Попередній квартал"
            next-label="Наступний квартал"
            @previous="step(-1)"
            @next="step(1)"
        />

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <EmptyState v-if="data && data.profiles.length === 0" text="У цьому кварталі немає активних ФОП." />
            <OverviewCard
                v-for="entry in data?.profiles ?? []"
                :key="entry.profile.id"
                :entry="entry"
                :year="year"
                :quarter="quarter"
                :current="entry.profile.id === profileId"
                @open="open(entry.profile.id, $event)"
            />
            <button
                v-if="hiddenCount > 0"
                type="button"
                class="flex min-h-11 items-center self-center text-14 font-medium text-accent"
                @click="router.replace({ query: { ...route.query, inactive: '1' } })"
            >
                Показати неактивні ({{ hiddenCount }})
            </button>
        </LoadState>
    </SubScreen>
</template>
