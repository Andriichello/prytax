<script setup lang="ts">
import { computed } from 'vue';
import type { BackupRecord, BackupRunRecord } from '@/api/types';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatBytes, keptLine, kyivTime, runKindLabel } from '@/utils/backups';
import { formatDayMonth, formatDayMonthWords, kyivDate } from '@/utils/dates';

/**
 * A run of Резервні копії (M-BK-Backups, D-BK-Backups; backups.md §4.8): its date and time with the kind's chip, how
 * long its copies stay (or «доки не видалите» with «🗑 Видалити» for an owner, on a manual run), «⤓ Усі ФОП · ZIP»
 * when it has two ФОП or more; then a row per ФОП with its status (Готово, Без змін, Помилка, Створюється, У черзі),
 * the size and documents (or the copy it stands for, or the error) and its download, or «Повторити» on a failure.
 */
interface Profile {
    initials: string;
    full_name: string;
    is_active: boolean;
}

const { run, profileOf, canDelete = false, canRun = false } = defineProps<{
    run: BackupRunRecord;
    profileOf: (id: number) => Profile | null;
    canDelete?: boolean;
    canRun?: boolean;
}>();

const emit = defineEmits<{
    download: [backup: BackupRecord];
    retry: [profileId: number];
    bundle: [run: BackupRunRecord];
    delete: [run: BackupRunRecord];
}>();

const { user } = useSession();
const { isDesktop } = useBreakpoint();

const running = computed(() => run.status === 'queued' || run.status === 'running');

/** Every profile of the run, those without a backup yet waiting in the queue. */
const rows = computed(() => {
    const backups = run.backups ?? [];
    const ids = run.profile_ids ?? backups.map((backup) => backup.profile_id);

    return [...new Set([...ids, ...backups.map((backup) => backup.profile_id)])].map((id) => ({
        id,
        profile: profileOf(id),
        backup: backups.find((backup) => backup.profile_id === id) ?? null,
    }));
});

const second = computed(() => {
    if (run.kind === 'manual') {
        const by = run.triggered_by_user_id === user.value?.id ? user.value?.name : null;

        return [running.value ? null : t('settings.backups.untilDeleted'), by].filter(Boolean).join(' · ');
    }

    const kept = keptLine(run.backups?.find((backup) => backup.status === 'completed'));

    if (run.kind === 'automatic') {
        const why = run.reason === 'organization_deleted' ? t('settings.backups.beforeOrganizationDeleted') : t('settings.backups.beforeProfileDeleted');

        return [why, kept].filter(Boolean).join(' · ');
    }

    return kept;
});

const bundleable = computed(() => !running.value && rows.value.length >= 2);

type Chip = { label: string; class: string };

function chipOf(backup: BackupRecord | null): Chip {
    if (!backup || backup.status === 'pending') {
        return running.value && backup
            ? { label: t('settings.backups.statusWriting'), class: 'bg-accent-soft text-accent-hover' }
            : { label: t('settings.backups.statusQueued'), class: 'border border-dashed border-switch-off text-ink-3' };
    }

    switch (backup.status) {
        case 'completed':
            return { label: t('settings.backups.statusDone'), class: 'bg-positive-soft text-positive' };
        case 'skipped_unchanged':
            return { label: t('settings.backups.statusUnchanged'), class: 'bg-neutral-soft text-ink-2' };
        default:
            return { label: t('settings.backups.statusFailed'), class: 'bg-negative-soft text-negative' };
    }
}

function metaOf(backup: BackupRecord | null): string {
    if (!backup || backup.status === 'pending') {
        return running.value && backup ? t('settings.backups.writingNow') : '';
    }

    if (backup.status === 'skipped_unchanged') {
        return backup.previous
            ? t('settings.backups.copyFrom', { size: formatBytes(backup.previous.bytes), date: formatDayMonth(kyivDate(backup.previous.created_at)) })
            : '';
    }

    if (backup.status === 'failed') {
        return t('settings.backups.archiveError');
    }

    return backup.documents !== null
        ? `${formatBytes(backup.bytes)} · ${t('core.counts.documents', { count: backup.documents })}`
        : formatBytes(backup.bytes);
}

const downloadable = (backup: BackupRecord | null): boolean => Boolean(backup && (
    (backup.status === 'completed' && backup.has_archive) || (backup.status === 'skipped_unchanged' && backup.previous)
));
</script>

<template>
    <article class="@container flex flex-col rounded-card border border-line bg-surface px-4">
        <header class="flex flex-col gap-1 py-3.5">
            <div class="flex items-center justify-between gap-3">
                <span class="flex min-w-0 items-center gap-2">
                    <span class="text-16 font-semibold">{{ formatDayMonthWords(kyivDate(run.created_at)) }}, <span class="num">{{ kyivTime(run.created_at) }}</span></span>
                    <span
                        class="shrink-0 rounded-badge px-2 py-0.5 text-12 font-semibold"
                        :class="run.kind === 'manual' ? 'bg-accent-soft text-accent-hover' : run.kind === 'automatic' ? 'border border-dashed border-line-input text-ink-2' : 'border border-line-input text-ink-2'"
                    >{{ runKindLabel(run.kind) }}</span>
                </span>
                <button v-if="isDesktop && bundleable" type="button" class="flex shrink-0 items-center gap-1 text-13 font-medium text-accent" @click="emit('bundle', run)">
                    <Icon name="download" :size="14" /> {{ t('settings.backups.allZip') }}
                </button>
            </div>
            <div class="flex items-center justify-between gap-3">
                <span class="min-w-0 text-12 text-ink-3">{{ second }}</span>
                <button v-if="run.kind === 'manual' && canDelete && !running" type="button" class="flex shrink-0 items-center gap-1 text-13 font-medium text-ink-2" @click="emit('delete', run)">
                    <Icon name="trash" :size="14" /> {{ t('settings.backups.delete') }}
                </button>
                <button v-else-if="!isDesktop && bundleable" type="button" class="flex shrink-0 items-center gap-1 text-13 font-medium text-accent" @click="emit('bundle', run)">
                    <Icon name="download" :size="14" /> {{ t('settings.backups.allZip') }}
                </button>
            </div>
        </header>
        <div
            v-for="row in rows"
            :key="row.id"
            class="items-center gap-3 border-t border-divider py-3"
            :class="isDesktop ? 'grid grid-cols-[minmax(0,1fr)_100px_72px] @min-[720px]:grid-cols-[minmax(0,1fr)_100px_220px_72px]' : 'flex'"
        >
            <span class="flex min-w-0 items-center gap-3" :class="isDesktop ? '' : 'flex-1'">
                <Avatar :initials="row.profile?.initials ?? '?'" :variant="row.profile && !row.profile.is_active ? 'light' : 'dark'" :size="36" :class="row.profile && !row.profile.is_active ? 'opacity-70' : ''" />
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="truncate text-15">{{ row.profile?.full_name ?? `#${row.id}` }}</span>
                    <span v-if="!isDesktop" class="flex min-w-0 items-center gap-1.5">
                        <span class="shrink-0 rounded-[6px] px-1.5 py-0.5 text-11 font-semibold" :class="chipOf(row.backup).class">{{ chipOf(row.backup).label }}</span>
                        <span class="truncate text-12" :class="row.backup?.status === 'failed' ? 'text-negative' : 'text-ink-3'">{{ metaOf(row.backup) }}</span>
                    </span>
                    <template v-else>
                        <span class="truncate text-12 @min-[720px]:hidden" :class="row.backup?.status === 'failed' ? 'text-negative' : 'text-ink-3'">{{ metaOf(row.backup) }}</span>
                        <span v-if="row.profile && !row.profile.is_active" class="text-12 text-ink-3">{{ t('settings.backups.inactive') }}</span>
                    </template>
                </span>
            </span>
            <template v-if="isDesktop">
                <span><span class="rounded-[6px] px-1.5 py-0.5 text-11 font-semibold" :class="chipOf(row.backup).class">{{ chipOf(row.backup).label }}</span></span>
                <span class="hidden truncate text-13 @min-[720px]:block" :class="row.backup?.status === 'failed' ? 'text-negative' : 'text-ink-3'">{{ metaOf(row.backup) }}</span>
            </template>
            <span class="flex shrink-0 justify-end">
                <button
                    v-if="downloadable(row.backup)"
                    type="button"
                    :aria-label="t('settings.backups.downloadTitle')"
                    class="flex size-10 items-center justify-center rounded-control border border-line-input bg-surface text-ink"
                    @click="emit('download', row.backup!)"
                >
                    <Icon name="download" :size="18" />
                </button>
                <button v-else-if="row.backup?.status === 'failed' && canRun" type="button" class="text-14 font-medium text-accent" @click="emit('retry', row.id)">
                    {{ t('settings.backups.retry') }}
                </button>
            </span>
        </div>
    </article>
</template>
