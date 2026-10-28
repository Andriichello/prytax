<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, BackupPolicyRecord, BackupStatusSummary } from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import NumberStepper from '@/components/form/NumberStepper.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SwitchCard from '@/components/form/SwitchCard.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { weekdayNames } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';

/**
 * Розклад і зберігання (M-BK-Policy and its edit states, D-BK-Policy; backups.md §4.8): four accordion groups, one open
 * at a time, each saving its own fields (`PUT organization/backup-policy`): Розклад (on or off, how often, the weekday
 * or the day of the month; the time is the organization's), Зберігання (a preset, derived from the three numbers, or
 * «Власний»), Що входить (all ФОП or chosen ones, documents, the change log, skipping unchanged ФОП) and Сповіщення.
 * Accountants see it without «Змінити».
 */
type Group = 'schedule' | 'retention' | 'content' | 'notify';
type Frequency = BackupPolicyRecord['frequency'];
type Notify = BackupPolicyRecord['notify'];

const PRESETS: { key: string; counts: [number, number, number] }[] = [
    { key: 'standard', counts: [7, 4, 12] },
    { key: 'minimum', counts: [3, 0, 3] },
    { key: 'archive', counts: [7, 4, 36] },
];

const { can } = useSession();
const { profiles } = useCurrentProfile();
const accordion = provideAccordion();

const canConfigure = computed(() => can('configure', 'backup-policies'));

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [policy, status] = await Promise.all([
        api.get<ApiResponse<BackupPolicyRecord>>('organization/backup-policy', undefined, signal),
        api.get<ApiResponse<BackupStatusSummary>>('organization/backup-status', undefined, signal),
    ]);

    return { policy: policy.data, time: status.data.policy.time_of_day };
}, []);

const saved = computed(() => data.value?.policy ?? null);
const form = reactive<BackupPolicyRecord>({
    enabled: true,
    frequency: 'daily',
    weekday: 1,
    month_day: 1,
    retention_daily: 7,
    retention_weekly: 4,
    retention_monthly: 12,
    include_documents: true,
    include_audit_log: false,
    profiles_mode: 'all',
    profile_ids: [],
    skip_unchanged: true,
    notify: 'failures',
    next_run_at: null,
});

const saving = ref(false);
const saveError = ref<unknown>(null);

function open(): void {
    if (saved.value) {
        Object.assign(form, { ...saved.value, profile_ids: [...saved.value.profile_ids] });
        form.weekday ??= 1;
        form.month_day ??= 1;
    }

    saveError.value = null;
}

/** The fields each group sends. */
function fieldsOf(group: Group): Partial<BackupPolicyRecord> {
    switch (group) {
        case 'schedule':
            return {
                enabled: form.enabled,
                frequency: form.frequency,
                weekday: form.frequency === 'weekly' ? form.weekday : null,
                month_day: form.frequency === 'monthly' ? form.month_day : null,
            };
        case 'retention':
            return { retention_daily: form.retention_daily, retention_weekly: form.retention_weekly, retention_monthly: form.retention_monthly };
        case 'content':
            return {
                profiles_mode: form.profiles_mode,
                profile_ids: form.profiles_mode === 'selected' ? form.profile_ids : [],
                include_documents: form.include_documents,
                include_audit_log: form.include_audit_log,
                skip_unchanged: form.skip_unchanged,
            };
        default:
            return { notify: form.notify };
    }
}

const contentInvalid = computed(() => form.profiles_mode === 'selected' && form.profile_ids.length === 0);

async function save(group: Group): Promise<void> {
    if (group === 'content' && contentInvalid.value) {
        return;
    }

    saving.value = true;
    saveError.value = null;

    try {
        await api.put('organization/backup-policy', fieldsOf(group));
        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const groupError = computed(() => (saveError.value ? errorMessage(saveError.value) : null));

/* Read rows */
const FREQUENCIES = computed(() => [
    { value: 'daily' as Frequency, label: t('settings.backups.daily') },
    { value: 'weekly' as Frequency, label: t('settings.backups.weekly') },
    { value: 'monthly' as Frequency, label: t('settings.backups.monthly') },
]);
const NOTIFIES = computed(() => [
    { value: 'failures' as Notify, label: t('settings.backups.notifyFailures') },
    { value: 'always' as Notify, label: t('settings.backups.notifyAlways') },
    { value: 'never' as Notify, label: t('settings.backups.notifyNever') },
]);
const MODES = computed(() => [
    { value: 'all' as const, label: t('settings.backups.allIncludingNew') },
    { value: 'selected' as const, label: t('settings.backups.selected') },
]);

const weekdays = computed(() => weekdayNames());

const frequencyText = (policy: BackupPolicyRecord): string => {
    switch (policy.frequency) {
        case 'weekly':
            return t('settings.backups.weeklyOn', { day: weekdays.value[(policy.weekday ?? 1) - 1] ?? '' });
        case 'monthly':
            return t('settings.backups.monthlyOn', { day: policy.month_day ?? 1 });
        default:
            return t('settings.backups.daily');
    }
};

/** The preset of three numbers, or «Власний». */
const presetOf = (counts: [number, number, number]): string => PRESETS.find((preset) => preset.counts.every((count, index) => count === counts[index]))?.key ?? 'custom';

const presetLabel = (key: string): string => ({
    standard: t('settings.backups.presetStandard'),
    minimum: t('settings.backups.presetMinimum'),
    archive: t('settings.backups.presetArchive'),
    custom: t('settings.backups.presetCustom'),
})[key] ?? key;

const PRESET_OPTIONS = computed(() => [...PRESETS.map((preset) => ({ value: preset.key, label: presetLabel(preset.key) })), { value: 'custom', label: presetLabel('custom') }]);

const formPreset = computed({
    get: () => presetOf([form.retention_daily, form.retention_weekly, form.retention_monthly]),
    set: (key: string) => {
        const preset = PRESETS.find((item) => item.key === key);

        if (preset) {
            [form.retention_daily, form.retention_weekly, form.retention_monthly] = preset.counts;
        }
    },
});

const lastText = (count: number, template: 'perDay' | 'perWeek' | 'perMonth'): string => {
    const last = t('core.counts.lastBackups', { count });

    return template === 'perDay'
        ? t('settings.backups.perDay', { last })
        : template === 'perWeek' ? t('settings.backups.perWeek', { last }) : t('settings.backups.perMonth', { last });
};

const profilesText = (policy: BackupPolicyRecord): string => (policy.profiles_mode === 'all'
    ? t('settings.backups.allCount', { count: profiles.value.length })
    : t('settings.backups.selectedCount', { count: policy.profile_ids.length }));

function toggleProfile(id: number): void {
    form.profile_ids = form.profile_ids.includes(id) ? form.profile_ids.filter((item) => item !== id) : [...form.profile_ids, id];
}
</script>

<template>
    <SubScreen :title="t('settings.backups.policy')" :back="{ name: 'backups' }">
        <template #aside>
            <SideNote :text="t('settings.backups.retentionNote')" />
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="saved && data">
                <AccordionGroup
                    id="schedule"
                    :title="t('settings.backups.scheduleGroup')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="groupError"
                    @open="open"
                    @save="save('schedule')"
                >
                    <ReadRow :label="t('settings.backups.automaticRow')" :value="saved.enabled ? t('settings.backups.on') : t('settings.backups.off')" />
                    <ReadRow :label="t('settings.backups.frequency')" :value="frequencyText(saved)" />
                    <ReadRow :label="t('settings.backups.time')" :value="t('settings.backups.timeValue', { time: data.time })" />
                    <template #edit>
                        <SwitchCard v-model="form.enabled" :title="t('settings.backups.enableSwitch')" :sub="t('settings.backups.enableHint')" />
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.backups.frequency') }}</span>
                            <SegmentedControl v-model="form.frequency" :label="t('settings.backups.frequency')" :options="FREQUENCIES" :disabled="!form.enabled" />
                        </div>
                        <div v-if="form.frequency === 'weekly'" class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.backups.weekday') }}</span>
                            <div role="radiogroup" :aria-label="t('settings.backups.weekday')" class="grid grid-cols-7 gap-1.5">
                                <button
                                    v-for="(name, index) in weekdays"
                                    :key="name"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.weekday === index + 1 ? 'true' : 'false'"
                                    class="flex h-10 items-center justify-center rounded-control text-14"
                                    :class="form.weekday === index + 1 ? 'bg-ink font-medium text-white' : 'border border-line-input bg-surface'"
                                    @click="form.weekday = index + 1"
                                >
                                    {{ name }}
                                </button>
                            </div>
                        </div>
                        <NumberStepper
                            v-if="form.frequency === 'monthly'"
                            v-model="form.month_day as number"
                            :label="t('settings.backups.monthDay')"
                            :min="1"
                            :max="28"
                        />
                        <p class="m-0 flex gap-2 rounded-control bg-muted px-3.5 py-3 text-13 leading-[1.45] text-ink-2">
                            <Icon name="info" :size="18" class="shrink-0" />
                            <span>{{ t('settings.backups.timeInfo', { time: data.time }) }}</span>
                        </p>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="retention"
                    :title="t('settings.backups.retentionGroup')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="groupError"
                    @open="open"
                    @save="save('retention')"
                >
                    <ReadRow :label="t('settings.backups.preset')" :value="presetLabel(presetOf([saved.retention_daily, saved.retention_weekly, saved.retention_monthly]))" />
                    <ReadRow :label="t('settings.backups.daysRow')" :value="lastText(saved.retention_daily, 'perDay')" />
                    <ReadRow :label="t('settings.backups.weeksRow')" :value="lastText(saved.retention_weekly, 'perWeek')" />
                    <ReadRow :label="t('settings.backups.monthsRow')" :value="lastText(saved.retention_monthly, 'perMonth')" />
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.backups.preset') }}</span>
                            <SegmentedControl v-model="formPreset" :label="t('settings.backups.preset')" :options="PRESET_OPTIONS" />
                            <span class="text-12 leading-[1.45] text-ink-3">{{ t('settings.backups.presetHint') }}</span>
                        </div>
                        <div class="flex flex-col divide-y divide-divider">
                            <NumberStepper v-model="form.retention_daily" :label="t('settings.backups.lastDays')" :hint="t('settings.backups.daysHint')" :max="31" />
                            <NumberStepper v-model="form.retention_weekly" :label="t('settings.backups.lastWeeks')" :hint="t('settings.backups.weeksHint')" :max="12" />
                            <NumberStepper v-model="form.retention_monthly" :label="t('settings.backups.lastMonths')" :hint="t('settings.backups.monthsHint')" :max="36" />
                        </div>
                        <p class="m-0 flex gap-2 rounded-control bg-muted px-3.5 py-3 text-13 leading-[1.45] text-ink-2">
                            <Icon name="info" :size="18" class="shrink-0" />
                            <span>{{ t('settings.backups.archiveInfo') }}</span>
                        </p>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="content"
                    :title="t('settings.backups.contentGroup')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="contentInvalid ? t('settings.backups.noneSelected') : groupError"
                    @open="open"
                    @save="save('content')"
                >
                    <ReadRow :label="t('settings.backups.profilesRow')" :value="profilesText(saved)" />
                    <ReadRow :label="t('settings.backups.documentsRow')" :value="saved.include_documents ? t('settings.backups.documentsValue') : t('settings.backups.documentsOff')" />
                    <ReadRow :label="t('settings.backups.auditRow')" :value="saved.include_audit_log ? t('settings.backups.yes') : t('settings.backups.no')" />
                    <ReadRow :label="t('settings.backups.unchangedRow')" :value="saved.skip_unchanged ? t('settings.backups.unchangedSkip') : t('settings.backups.unchangedCopy')" />
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.backups.profilesRow') }}</span>
                            <SegmentedControl v-model="form.profiles_mode" :label="t('settings.backups.profilesRow')" :options="MODES" />
                        </div>
                        <div v-if="form.profiles_mode === 'selected'" class="flex flex-col gap-1">
                            <label v-for="item in profiles" :key="item.id" class="flex min-h-11 items-center gap-3">
                                <input type="checkbox" class="size-5 accent-accent" :checked="form.profile_ids.includes(item.id)" @change="toggleProfile(item.id)">
                                <span class="flex min-w-0 flex-col">
                                    <span class="truncate text-15">{{ item.full_name }}</span>
                                    <span v-if="!item.is_active" class="text-12 text-ink-3">{{ t('settings.backups.inactiveKeeps') }}</span>
                                </span>
                            </label>
                            <span class="text-12 text-ink-3">{{ t('settings.backups.newNotAdded') }}</span>
                        </div>
                        <SwitchCard v-model="form.include_documents" :title="t('settings.backups.documentsRow')" :sub="t('settings.backups.documentsHint')" />
                        <SwitchCard v-model="form.include_audit_log" :title="t('settings.backups.auditRow')" :sub="t('settings.backups.auditHint')" />
                        <SwitchCard v-model="form.skip_unchanged" :title="t('settings.backups.skipSwitch')" :sub="t('settings.backups.skipHint')" />
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="notify"
                    :title="t('settings.backups.notifyGroup')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="groupError"
                    @open="open"
                    @save="save('notify')"
                >
                    <ReadRow :label="t('settings.backups.notifyRow')" :value="NOTIFIES.find((option) => option.value === saved!.notify)?.label" />
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.backups.notifyRow') }}</span>
                            <SegmentedControl v-model="form.notify" :label="t('settings.backups.notifyRow')" :options="NOTIFIES" />
                            <span class="text-12 leading-[1.45] text-ink-3">{{ t('settings.backups.notifyHint') }}</span>
                        </div>
                    </template>
                </AccordionGroup>

                <Dimmable>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">
                        {{ canConfigure ? t('settings.sideNotes.oneGroup') : t('settings.backups.accountantNote') }}
                    </p>
                </Dimmable>
            </template>
        </LoadState>
    </SubScreen>
</template>
