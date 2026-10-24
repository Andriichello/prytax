<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Alert, AlertCategory, AlertCounts, ApiResponse, Paginated } from '@/api/types';
import AlertCard from '@/components/alert/AlertCard.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Chip from '@/components/ui/Chip.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { errorMessage } from '@/utils/errors';

/**
 * Сповіщення: the profile's notifications, newest first, filtered by category or unread. Opening or scrolling the
 * list marks nothing read.
 */
type Shown = 'all' | 'unread' | AlertCategory;

const CATEGORIES: { value: AlertCategory; label: string }[] = [
    { value: 'funds', get label() { return t('shell.alerts.funds'); } },
    { value: 'invoices', get label() { return t('shell.nav.invoices'); } },
    { value: 'deadlines', get label() { return t('shell.alerts.deadlines'); } },
];

const route = useRoute();
const router = useRouter();
const { profileId, path, markOneRead, setUnreadCount } = useCurrentProfile();

const shown = computed<Shown>(() => {
    const value = route.query.show;

    return value === 'unread' || CATEGORIES.some((category) => category.value === value) ? (value as Shown) : 'all';
});

const alerts = ref<Alert[]>([]);
const nextPage = ref<number | null>(null);
const loadingMore = ref(false);
const actionError = ref<unknown>(null);

function query(page: number) {
    return {
        filter: {
            category: CATEGORIES.some((category) => category.value === shown.value) ? shown.value : undefined,
            unread: shown.value === 'unread' ? true : undefined,
        },
        page: { number: page, size: 30 },
    };
}

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [list, counted] = await Promise.all([
        api.get<Paginated<Alert>>(path('alerts'), query(1), signal),
        api.get<ApiResponse<AlertCounts>>(path('alerts/counts'), undefined, signal),
    ]);

    return { list, counts: counted.data };
}, [profileId, shown]);

const counts = computed(() => data.value?.counts ?? null);

watch(data, (loaded) => {
    alerts.value = loaded?.list.data ?? [];
    nextPage.value = loaded?.list.meta.links.next ? 2 : null;

    if (loaded) {
        setUnreadCount(loaded.counts.unread);
    }
});

/** Notifications, whose read is on its way: a second tap meanwhile doesn't count them twice. */
const reading = new Set<number>();

async function loadMore(): Promise<void> {
    if (nextPage.value === null) {
        return;
    }

    loadingMore.value = true;

    try {
        const list = await api.get<Paginated<Alert>>(path('alerts'), query(nextPage.value));

        alerts.value.push(...list.data);
        nextPage.value = list.meta.links.next ? nextPage.value + 1 : null;
    } catch (caught) {
        actionError.value = caught;
    } finally {
        loadingMore.value = false;
    }
}

async function markRead(alert: Alert): Promise<void> {
    if (reading.has(alert.id)) {
        return;
    }

    reading.add(alert.id);
    actionError.value = null;

    try {
        const { data } = await api.post<ApiResponse<Alert>>(path(`alerts/${alert.id}/read`));
        const index = alerts.value.findIndex((item) => item.id === alert.id);

        if (index !== -1) {
            alerts.value[index] = { ...alerts.value[index], ...data };
        }

        if (counts.value) {
            counts.value.unread = Math.max(0, counts.value.unread - 1);
            counts.value.categories[alert.category].unread = Math.max(0, counts.value.categories[alert.category].unread - 1);
        }

        markOneRead();
    } catch (caught) {
        actionError.value = caught;
    } finally {
        reading.delete(alert.id);
    }
}

function show(value: Shown): void {
    void router.replace({ query: value === 'all' ? {} : { show: value } });
}

const chips = computed(() => {
    const all = counts.value;

    return [
        { value: 'all' as Shown, label: t('shell.alerts.all'), count: all?.all ?? 0 },
        { value: 'unread' as Shown, label: t('shell.alerts.unreadFilter'), count: all?.unread ?? 0 },
        ...CATEGORIES.map((category) => ({
            value: category.value as Shown,
            label: category.label,
            count: all?.categories[category.value]?.all ?? 0,
        })),
    ];
});
</script>

<template>
    <SubScreen :title="t('shell.alerts.notifications')">
        <template #action>
            <RouterLink
                :to="{ name: 'settings-taxes' }"
                :aria-label="t('shell.alerts.notificationSettings')"
                class="flex size-11 items-center justify-center text-ink hover:text-ink"
            >
                <Icon name="sliders" :size="22" />
            </RouterLink>
        </template>

        <div class="flex flex-wrap gap-2">
            <Chip v-for="chip in chips" :key="chip.value" :selected="shown === chip.value" @click="show(chip.value)">
                {{ chip.label }}<template v-if="chip.count"> · {{ chip.count }}</template>
            </Chip>
        </div>
        <p class="m-0 text-13 leading-[1.45] text-ink-3">
            {{ t('shell.alerts.readHint') }}
        </p>
        <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(actionError) }}</p>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <EmptyState
                v-if="alerts.length === 0"
                :text="shown === 'unread' ? t('shell.alerts.noUnreadNotifications') : t('shell.alerts.noNotifications')"
            />
            <AlertCard v-for="alert in alerts" :key="alert.id" :alert="alert" @read="markRead" />
            <button
                v-if="nextPage !== null"
                type="button"
                class="flex min-h-11 items-center self-center text-14 font-medium text-accent"
                :disabled="loadingMore"
                @click="loadMore"
            >
                {{ loadingMore ? t('core.common.loading') : t('shell.alerts.showMore') }}
            </button>
        </LoadState>
    </SubScreen>
</template>
