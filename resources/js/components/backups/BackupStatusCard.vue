<script setup lang="ts">
import { computed } from 'vue';
import type { BackupStatusSummary } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatBytes, kyivTime, relativeDay } from '@/utils/backups';

/**
 * The status card of Резервні копії (M-BK-Backups, M-BK-States; backups.md §4.8) in its five states: the last run
 * done (✓ green), a run in progress (with its progress), a partial run (⚠ red, the failed ФОП named), no copies yet,
 * and the schedule off (⚠ amber). Under it «Наступна», and on mobile (`policyRow`) the link to Розклад і зберігання.
 */
const { status, profileName, policyRow = true } = defineProps<{
    status: BackupStatusSummary;
    /** The short name of a profile, by id. */
    profileName: (id: number) => string;
    policyRow?: boolean;
}>();

const { user } = useSession();

type State = 'running' | 'partial' | 'none' | 'off' | 'done';

const run = computed(() => status.latest_run);
const backups = computed(() => run.value?.backups ?? []);
const doneCount = computed(() => backups.value.filter((backup) => backup.status === 'completed' || backup.status === 'skipped_unchanged').length);
const failed = computed(() => backups.value.filter((backup) => backup.status === 'failed'));

const state = computed<State>(() => {
    if (run.value && (run.value.status === 'queued' || run.value.status === 'running')) {
        return 'running';
    }

    if (!status.policy.enabled) {
        return 'off';
    }

    if (!status.last_completed_at && !run.value) {
        return 'none';
    }

    return run.value && (run.value.status === 'partial' || run.value.status === 'failed') ? 'partial' : 'done';
});

const LOOKS: Record<State, { icon: IconName; circle: string }> = {
    done: { icon: 'check', circle: 'bg-positive-soft text-positive' },
    running: { icon: 'spinner', circle: 'bg-accent-soft text-accent' },
    partial: { icon: 'info', circle: 'bg-negative-soft text-negative' },
    none: { icon: 'clock', circle: 'bg-muted text-ink-3' },
    off: { icon: 'info', circle: 'bg-warning-soft text-warning' },
};

const total = computed(() => run.value?.profile_ids?.length ?? backups.value.length);

const lines = computed(() => {
    const kind = run.value?.kind === 'manual'
        ? t('settings.backups.kindManual').toLocaleLowerCase()
        : run.value?.kind === 'automatic' ? t('settings.backups.kindAutomaticLower') : t('settings.backups.kindScheduledLower');

    switch (state.value) {
        case 'running':
            return {
                kicker: t('settings.backups.now'),
                headline: t('settings.backups.creating'),
                sub: [
                    t('settings.backups.progress', { done: doneCount.value, total: total.value }),
                    run.value?.kind === 'manual'
                        ? (run.value.triggered_by_user_id === user.value?.id && user.value?.name ? t('settings.backups.manualBy', { name: user.value.name }) : t('settings.backups.manualShort'))
                        : kind,
                ].join(' · '),
            };
        case 'partial':
            return {
                kicker: t('settings.backups.lastCopy'),
                headline: t('settings.backups.dayTime', { day: relativeDay(run.value?.created_at), time: kyivTime(run.value?.created_at) }),
                sub: t('settings.backups.partial', {
                    done: doneCount.value,
                    total: total.value,
                    name: failed.value.map((backup) => profileName(backup.profile_id)).join(', '),
                }),
            };
        case 'none':
            return {
                kicker: t('settings.backups.noCopiesKicker'),
                headline: t('settings.backups.noCopies'),
                sub: t('settings.backups.firstTonight', { time: status.policy.time_of_day }),
            };
        case 'off':
            return {
                kicker: t('settings.backups.automaticCopies'),
                headline: t('settings.backups.off'),
                sub: status.last_completed_at
                    ? t('settings.backups.lastCopyOn', { date: relativeDay(status.last_completed_at), time: kyivTime(status.last_completed_at), count: status.profiles })
                    : '',
            };
        default:
            return {
                kicker: t('settings.backups.lastCopy'),
                headline: t('settings.backups.dayTime', { day: relativeDay(status.last_completed_at), time: kyivTime(status.last_completed_at) }),
                sub: t('settings.backups.summary', { count: status.profiles, size: formatBytes(status.bytes), kind }),
            };
    }
});

const next = computed(() => (status.policy.enabled && status.policy.next_run_at
    ? t('settings.backups.nextAt', { day: relativeDay(status.policy.next_run_at), time: kyivTime(status.policy.next_run_at) })
    : '—'));

const frequency = computed(() => ({
    daily: t('settings.backups.daily'),
    weekly: t('settings.backups.weekly'),
    monthly: t('settings.backups.monthly'),
})[status.policy.frequency]);

const progress = computed(() => (total.value ? Math.round((doneCount.value / total.value) * 100) : 0));
</script>

<template>
    <section class="flex flex-col rounded-card border border-line bg-surface px-4">
        <div class="flex items-center gap-3.5 py-4">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full" :class="LOOKS[state].circle">
                <Icon :name="LOOKS[state].icon" :size="24" :class="state === 'running' ? 'animate-spin' : ''" />
            </span>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-12 text-ink-3">{{ lines.kicker }}</span>
                <span class="text-20 font-semibold">{{ lines.headline }}</span>
                <span v-if="lines.sub" class="text-13" :class="state === 'partial' ? 'text-negative' : 'text-ink-3'">{{ lines.sub }}</span>
            </span>
        </div>
        <div v-if="state === 'running'" class="-mt-1 mb-3 h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-accent transition-[width]" :style="{ width: `${progress}%` }" />
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-divider py-3 text-14">
            <span class="text-ink-2">{{ t('settings.backups.next') }}</span>
            <span class="num">{{ next }}</span>
        </div>
        <RouterLink v-if="policyRow" :to="{ name: 'backup-policy' }" class="flex items-center justify-between gap-3 border-t border-divider py-3 text-14 text-ink no-underline hover:text-ink">
            <span class="text-ink-2">{{ t('settings.backups.policy') }}</span>
            <span class="flex items-center gap-1">
                {{ status.policy.enabled ? t('settings.backups.policySummary', { frequency, months: status.policy.retention_monthly }) : t('settings.backups.off') }}
                <Icon name="chevronRight" :size="16" class="text-ink-3" />
            </span>
        </RouterLink>
    </section>
</template>
