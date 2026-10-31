<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, BackupRecord, BackupRunRecord, BackupStatusSummary, DeletedProfile, InstallationStatus, Paginated } from '@/api/types';
import BackupDownloadSheet from '@/components/backups/BackupDownloadSheet.vue';
import BackupImportFlow from '@/components/backups/BackupImportFlow.vue';
import BackupRunCard from '@/components/backups/BackupRunCard.vue';
import BackupRunSheet from '@/components/backups/BackupRunSheet.vue';
import BackupStatusCard from '@/components/backups/BackupStatusCard.vue';
import InstallationBackupForm from '@/components/installation/InstallationBackupForm.vue';
import InstallationRestoreFlow from '@/components/installation/InstallationRestoreFlow.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import RecoveryKitPanel from '@/components/settings/RecoveryKitPanel.vue';
import Avatar from '@/components/ui/Avatar.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import NewPill from '@/components/ui/NewPill.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useDesktopApp } from '@/composables/useDesktopApp';
import { useInstallationBackups } from '@/composables/useInstallationBackups';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatBytes, kyivTime, shortMoment } from '@/utils/backups';
import { formatDayMonth, kyivDate } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';

/**
 * Резервні копії (M-BK-Backups, D-BK-Backups, M-BK-States; backups.md §4.8), organization-level for owners and
 * accountants, in tabs. Резервні: the status card and «Створити зараз»; the bundle's banner while it is prepared and
 * once it is ready; the runs, newest first (4, 5 on desktop), with «Усі ФОП · ZIP» at the list's top right, each ФОП's
 * archive downloadable, a failed one retried, an owner's manual run deleted, and «Показати старіші»; while a run is in
 * progress the page reads it again every 3 seconds. Застосунок: saving a copy of the whole app (builds.md §4.11).
 * Відновлення: the deleted ФОП in their grace, with their restore and final copy, then a ФОП from an archive or the
 * whole app from a copy. Ключ: the desktop installation's recovery kit.
 */
const { can, user } = useSession();
const { profiles, profile } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

// In the desktop app, its owner reaches the installation's recovery kit here, and the note says what this computer keeps
const desktopApp = ref(false);
const showsRecoveryKit = ref(false);
// An owner backs up the whole app, and restores one, where it runs for one person on SQLite (builds.md §4.11)
const installation = ref<InstallationStatus | null>(null);

onMounted(async () => {
    desktopApp.value = await useDesktopApp().isDesktopApp();
    showsRecoveryKit.value = desktopApp.value && (user.value?.roles.includes('owner') ?? false);
    installation.value = await useInstallationBackups().status().catch(() => null);
});

/*
 * The tabs: the ФОП's backups, copies of the whole app, restoring (a ФОП from an archive, the whole app from a copy,
 * a deleted ФОП) and the desktop installation's recovery kit; each where it applies. The tab is kept in the address.
 */
type Tab = 'backups' | 'copies' | 'restore' | 'recovery';

const route = useRoute();
const router = useRouter();

const fullBackups = computed(() => Boolean(installation.value?.enabled && installation.value.allowed));
const fullRestorable = computed(() => fullBackups.value && Boolean(installation.value?.supported));
const tabs = computed(() => [
    { value: 'backups' as Tab, label: t('settings.backups.tabs.backups') },
    ...(fullBackups.value ? [{ value: 'copies' as Tab, label: t('settings.backups.tabs.copies') }] : []),
    ...(can('import', 'backups') || fullRestorable.value || (data.value?.deleted.length ?? 0) > 0 ? [{ value: 'restore' as Tab, label: t('settings.backups.tabs.restore') }] : []),
    ...(showsRecoveryKit.value ? [{ value: 'recovery' as Tab, label: t('settings.backups.tabs.recovery') }] : []),
]);
const tab = computed<Tab>({
    get: () => tabs.value.find((item) => item.value === route.query.tab)?.value ?? 'backups',
    set: (value) => void router.replace({ query: { ...route.query, tab: value === 'backups' ? undefined : value } }),
});

/* What the Відновлення tab restores: ФОП from an archive, or the whole app from a copy */
const restoreKind = ref<'profiles' | 'app'>('profiles');
const restoreKinds = computed(() => [
    ...(can('import', 'backups') ? [{ value: 'profiles' as const, label: t('settings.backups.tabs.profilesFromArchive') }] : []),
    ...(fullRestorable.value ? [{ value: 'app' as const, label: t('settings.backups.tabs.wholeApp') }] : []),
]);
const shownRestoreKind = computed(() => restoreKinds.value.find((kind) => kind.value === restoreKind.value)?.value ?? restoreKinds.value[0]?.value ?? null);

const canRun = computed(() => can('create', 'backup-runs'));
const canDelete = computed(() => can('delete', 'backups'));
const canRestore = computed(() => can('restore', 'profiles'));
const pageSize = computed(() => (isDesktop.value ? 5 : 4));

const shownPages = ref(1);

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [status, runs, deleted] = await Promise.all([
        api.get<ApiResponse<BackupStatusSummary>>('organization/backup-status', undefined, signal),
        api.get<Paginated<BackupRunRecord>>('organization/backup-runs', {
            include: 'backups',
            filter: { kind: { not: { eq: 'export' } } },
            page: { number: 1, size: pageSize.value * shownPages.value },
        }, signal),
        api.get<ApiResponse<DeletedProfile[]>>('organization/deleted-profiles', undefined, signal),
    ]);

    return { status: status.data, runs: runs.data, total: runs.meta.total, deleted: deleted.data };
}, [shownPages]);

const older = computed(() => Math.max(0, (data.value?.total ?? 0) - (data.value?.runs.length ?? 0)));

const profileOf = (id: number) => profiles.value.find((item) => item.id === id) ?? null;
const shortName = (id: number): string => profileOf(id)?.short_name ?? `#${id}`;

/* While a run is in progress, it is read again every 3 seconds */
const busyRun = computed(() => data.value?.runs.some((run) => run.status === 'queued' || run.status === 'running') ?? false);
let poll: ReturnType<typeof setTimeout> | null = null;

watch(data, () => {
    if (poll) {
        clearTimeout(poll);
        poll = null;
    }

    if (busyRun.value) {
        poll = setTimeout(() => void reload(), 3000);
    }
});

onBeforeUnmount(() => {
    if (poll) {
        clearTimeout(poll);
    }

    if (bundlePoll) {
        clearTimeout(bundlePoll);
    }
});

/* A manual run */
const runOpen = ref(false);
const starting = ref(false);
const startError = ref<unknown>(null);

async function start(scope: 'all' | 'current' | number): Promise<void> {
    starting.value = true;
    startError.value = null;

    try {
        await api.post('organization/backup-runs', {
            profiles: scope === 'all' ? 'all' : [scope === 'current' ? profile.value?.id : scope],
        });
        runOpen.value = false;
        await reload();
    } catch (caught) {
        startError.value = caught;
    } finally {
        starting.value = false;
    }
}

/* The organization's bundle of a run */
const bundle = ref<{ run: BackupRunRecord; status: 'building' | 'ready' | 'failed'; url?: string; expiresAt?: string } | null>(null);
let bundlePoll: ReturnType<typeof setTimeout> | null = null;

async function checkBundle(run: BackupRunRecord): Promise<void> {
    const { data: state } = await api.get<{ data: { status: string | null; url?: string; expires_at?: string } }>(`organization/backup-runs/${run.id}/bundle`);

    if (state.status === 'ready' && state.url) {
        bundle.value = { run, status: 'ready', url: state.url, expiresAt: state.expires_at };
    } else if (state.status === 'failed') {
        bundle.value = { run, status: 'failed' };
    } else {
        bundlePoll = setTimeout(() => void checkBundle(run), 2500);
    }
}

async function prepareBundle(run: BackupRunRecord | undefined): Promise<void> {
    if (!run) {
        return;
    }

    if (bundlePoll) {
        clearTimeout(bundlePoll);
    }

    bundle.value = { run, status: 'building' };

    try {
        await api.post(`organization/backup-runs/${run.id}/bundle`);
        await checkBundle(run);
    } catch (caught) {
        bundle.value = { run, status: 'failed' };
        actionError.value = caught;
    }
}

async function downloadBundle(): Promise<void> {
    if (bundle.value) {
        await checkBundle(bundle.value.run);

        if (bundle.value.url) {
            window.location.href = bundle.value.url;
        }
    }
}

const bundleRun = computed(() => data.value?.runs.find((run) => run.status !== 'queued' && run.status !== 'running' && (run.backups?.length ?? 0) >= 2));
const runSize = (run: BackupRunRecord): number => (run.backups ?? [])
    .reduce((sum, backup) => sum + (backup.status === 'skipped_unchanged' ? backup.previous?.bytes ?? 0 : backup.bytes ?? 0), 0);

/* A download */
const downloadOpen = ref(false);
const downloading = ref<BackupRecord | null>(null);

function openDownload(backup: BackupRecord): void {
    downloading.value = backup;
    downloadOpen.value = true;
}

/* Deleting a manual run's copies */
const actionError = ref<unknown>(null);
const deleting = ref<BackupRunRecord | null>(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);

function askDelete(run: BackupRunRecord): void {
    deleting.value = run;
    deleteOpen.value = true;
}

async function remove(): Promise<void> {
    deleteBusy.value = true;

    try {
        for (const backup of deleting.value?.backups ?? []) {
            await api.delete(`organization/backups/${backup.id}`);
        }

        deleteOpen.value = false;
        await reload();
    } catch (caught) {
        actionError.value = caught;
    } finally {
        deleteBusy.value = false;
    }
}

/* A deleted ФОП: its restore and its final copy */
const restoring = ref<DeletedProfile | null>(null);
const restoreOpen = ref(false);
const restoreBusy = ref(false);

async function restore(): Promise<void> {
    restoreBusy.value = true;

    try {
        await api.post(`profiles/${restoring.value?.id}/restore`);
        restoreOpen.value = false;
        await reload();
    } catch (caught) {
        actionError.value = caught;
    } finally {
        restoreBusy.value = false;
    }
}

const finalBackup = ref<DeletedProfile | null>(null);

function downloadFinal(deleted: DeletedProfile): void {
    finalBackup.value = deleted;
    downloading.value = {
        id: deleted.final_backup_id ?? '',
        bytes: deleted.final_backup_bytes,
        status: 'completed',
        snapshot_at: deleted.deleted_at,
        created_at: deleted.deleted_at ?? '',
    } as BackupRecord;
    downloadOpen.value = true;
}

const downloadName = computed(() => (finalBackup.value && downloading.value?.id === finalBackup.value.final_backup_id
    ? { initials: finalBackup.value.initials, name: finalBackup.value.full_name }
    : { initials: profileOf(downloading.value?.profile_id ?? 0)?.initials ?? '?', name: profileOf(downloading.value?.profile_id ?? 0)?.full_name ?? '' }));

/* The latest partial run's failed ФОП, which the states board names with its fix */
const failedOfLatest = computed(() => {
    const run = data.value?.status.latest_run;

    return run && (run.status === 'partial' || run.status === 'failed') ? (run.backups ?? []).filter((backup) => backup.status === 'failed') : [];
});

const previousOf = (profileId: number): string | null => {
    const earlier = data.value?.runs
        .flatMap((run) => run.backups ?? [])
        .find((backup) => backup.profile_id === profileId && backup.status === 'completed');

    return earlier ? formatDayMonth(kyivDate(earlier.created_at)) : null;
};
</script>

<template>
    <SubScreen :title="t('settings.backups.title')" :back="{ name: 'more' }">
        <template v-if="isDesktop && canRun && tab === 'backups'" #action>
            <NewPill :label="t('settings.backups.createCopyNow')" @click="runOpen = true" />
        </template>
        <template v-if="tabs.length > 1" #toolbar>
            <SegmentedControl v-model="tab" :label="t('settings.backups.tabs.label')" :options="tabs" />
        </template>

        <template v-if="isDesktop" #aside>
            <h2 class="m-0 text-14 font-semibold">{{ t('settings.sideNotes.howItWorks') }}</h2>
            <p class="m-0 text-13 leading-[1.5] text-ink-2">{{ desktopApp ? t('settings.backups.sideNoteDesktop') : t('settings.backups.sideNote') }}</p>
            <RouterLink :to="{ name: 'backup-policy' }" class="text-13 font-medium no-underline">{{ t('settings.backups.policy') }} ›</RouterLink>
        </template>

        <LoadState v-if="tab === 'backups'" :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="data">
                <BackupStatusCard :status="data.status" :profile-name="shortName" :policy-row="true" />

                <PrimaryButton v-if="!isDesktop && canRun" @click="runOpen = true"><Icon name="plus" :size="20" /> {{ t('settings.backups.createNow') }}</PrimaryButton>

                <p v-if="!data.status.policy.enabled" class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.backups.offText') }}</p>

                <section v-for="backup in failedOfLatest" :key="backup.id" class="flex flex-col gap-2 rounded-card border border-negative-soft bg-negative-soft/40 p-4">
                    <h2 class="m-0 text-15 font-semibold text-negative">{{ t('settings.backups.failedTitle', { name: shortName(backup.profile_id) }) }}</h2>
                    <p class="m-0 text-13 leading-[1.45] text-ink-2">
                        {{ t('settings.backups.failedText', { date: previousOf(backup.profile_id) ?? '—' }) }}
                    </p>
                    <button v-if="canRun" type="button" class="self-start text-14 font-medium text-accent" @click="start(backup.profile_id)">
                        {{ t('settings.backups.retryProfile', { name: shortName(backup.profile_id) }) }}
                    </button>
                </section>

                <section v-if="bundle" class="flex flex-col gap-2 rounded-card p-4" :class="bundle.status === 'ready' ? 'bg-positive-soft' : 'bg-accent-soft'" role="status">
                    <template v-if="bundle.status === 'ready'">
                        <h2 class="m-0 flex items-center gap-1.5 text-15 font-semibold text-positive">
                            <Icon name="check" :size="18" />
                            {{ t('settings.backups.bundleReady', { count: bundle.run.backups?.length ?? 0, size: formatBytes(runSize(bundle.run)) }) }}
                        </h2>
                        <p class="m-0 text-13 text-ink-2">
                            {{ t('settings.backups.bundleReadyText', { date: formatDayMonth(kyivDate(bundle.run.created_at)), time: kyivTime(bundle.run.created_at), until: shortMoment(bundle.expiresAt) }) }}
                        </p>
                        <PrimaryButton @click="downloadBundle"><Icon name="download" :size="18" /> {{ t('settings.backups.downloadZip') }}</PrimaryButton>
                    </template>
                    <template v-else-if="bundle.status === 'failed'">
                        <p class="m-0 text-14 text-negative">{{ t('settings.backups.bundleFailed') }}</p>
                    </template>
                    <template v-else>
                        <h2 class="m-0 flex items-center gap-1.5 text-15 font-semibold text-accent-hover">
                            <Icon name="spinner" :size="18" class="animate-spin" /> {{ t('settings.backups.bundlePreparing') }}
                        </h2>
                        <p class="m-0 text-13 leading-[1.45] text-ink-2">
                            {{ t('settings.backups.bundlePreparingText', {
                                date: formatDayMonth(kyivDate(bundle.run.created_at)),
                                time: kyivTime(bundle.run.created_at),
                                count: bundle.run.backups?.length ?? 0,
                                size: formatBytes(runSize(bundle.run)),
                            }) }}
                        </p>
                    </template>
                </section>

                <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(actionError) }}</p>

                <section class="flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-3">
                        <SectionTitle>{{ t('settings.backups.copies') }}</SectionTitle>
                        <button
                            v-if="canRun"
                            type="button"
                            class="-my-2 flex min-h-11 shrink-0 items-center gap-1.5 text-14 font-medium text-accent disabled:text-ink-4"
                            :disabled="!bundleRun"
                            @click="prepareBundle(bundleRun)"
                        >
                            <Icon name="download" :size="18" /> {{ t('settings.backups.allZip') }}
                        </button>
                    </div>
                    <span class="-mt-1 px-0.5 text-13 text-ink-3">{{ isDesktop ? t('settings.backups.newestFirstWide') : t('settings.backups.newestFirst') }}</span>
                    <div v-if="data.runs.length === 0" class="flex flex-col gap-1 rounded-card border border-dashed border-line-input p-4">
                        <span class="text-15 font-semibold">{{ t('settings.backups.emptyTitle') }}</span>
                        <span class="text-13 text-ink-3">{{ t('settings.backups.emptyText') }}</span>
                    </div>
                    <BackupRunCard
                        v-for="run in data.runs"
                        :key="run.id"
                        :run="run"
                        :profile-of="profileOf"
                        :can-delete="canDelete"
                        :can-run="canRun"
                        @download="openDownload"
                        @retry="start"
                        @bundle="prepareBundle"
                        @delete="askDelete"
                    />
                    <SecondaryButton v-if="older > 0" @click="shownPages += 1">{{ t('settings.backups.showOlder', { count: older }) }}</SecondaryButton>
                </section>

                <p v-if="!isDesktop" class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.backups.footnote') }}</p>
            </template>
        </LoadState>

        <template v-else-if="tab === 'copies' && installation">
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('settings.backups.full.hint') }}</p>
            <section v-if="installation.supported" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                <h2 class="m-0 text-16 font-semibold">{{ t('settings.backups.full.save') }}</h2>
                <InstallationBackupForm :label="t('settings.backups.full.save')" />
            </section>
            <p v-else class="m-0 text-14 leading-normal text-ink-3">{{ t('settings.backups.full.needsSqlite') }}</p>
            <p v-if="fullRestorable" class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.backups.full.restoreInTab') }}</p>
        </template>

        <template v-else-if="tab === 'restore'">
            <section v-if="data?.deleted.length" class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.backups.deletedProfiles') }}</SectionTitle>
                <article v-for="deleted in data?.deleted" :key="deleted.id" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                    <div class="flex items-center gap-3">
                        <Avatar :initials="deleted.initials" variant="light" :size="36" />
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate text-15 font-semibold">{{ deleted.full_name }}</span>
                            <span class="text-13 text-negative">
                                {{ t('settings.backups.deletedLine', { deleted: formatDayMonth(kyivDate(deleted.deleted_at)), purge: formatDayMonth(kyivDate(deleted.purge_at)) }) }}
                            </span>
                        </span>
                    </div>
                    <p class="m-0 text-13 leading-[1.45] text-ink-2">{{ t('settings.backups.deletedText', { purge: formatDayMonth(kyivDate(deleted.purge_at)) }) }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <SecondaryButton v-if="canRestore" @click="restoring = deleted; restoreOpen = true">{{ t('settings.backups.restoreProfile') }}</SecondaryButton>
                        <SecondaryButton :disabled="!deleted.final_backup_id" :class="canRestore ? '' : 'col-span-2'" @click="downloadFinal(deleted)">
                            <Icon name="download" :size="18" /> {{ t('settings.backups.finalCopy') }}
                        </SecondaryButton>
                    </div>
                </article>
            </section>

            <SegmentedControl v-if="restoreKinds.length > 1" v-model="restoreKind" :label="t('settings.backups.tabs.restoreWhat')" :options="restoreKinds" />
            <BackupImportFlow v-if="shownRestoreKind === 'profiles'" />
            <InstallationRestoreFlow v-else-if="shownRestoreKind === 'app'" />
            <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(actionError) }}</p>
        </template>

        <RecoveryKitPanel v-else-if="tab === 'recovery'" />

        <BackupRunSheet
            v-if="data"
            v-model:open="runOpen"
            :profiles-count="profiles.length"
            :size="data.status.bytes > 0 ? formatBytes(data.status.bytes) : null"
            :current-name="profile?.full_name ?? ''"
            :left="data.status.manual_runs_left"
            :busy="starting"
            :error="startError"
            @create="start"
        />
        <BackupDownloadSheet v-model:open="downloadOpen" :backup="downloading" :initials="downloadName.initials" :name="downloadName.name" />
        <ConfirmSheet
            v-model:open="deleteOpen"
            :title="t('settings.backups.deleteQuestion', { date: formatDayMonth(kyivDate(deleting?.created_at)), time: kyivTime(deleting?.created_at) })"
            :confirm-label="t('settings.backups.delete')"
            destructive
            :busy="deleteBusy"
            @confirm="remove"
        />
        <ConfirmSheet
            v-model:open="restoreOpen"
            :title="t('settings.backups.restoreQuestion', { name: restoring?.full_name ?? '' })"
            :confirm-label="t('settings.backups.restoreProfile')"
            :busy="restoreBusy"
            @confirm="restore"
        />
    </SubScreen>
</template>
