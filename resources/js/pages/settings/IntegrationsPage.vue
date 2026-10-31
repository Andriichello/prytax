<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import type { Paginated } from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SelectField from '@/components/form/SelectField.vue';
import SwitchCard from '@/components/form/SwitchCard.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useClaudeIntegration } from '@/composables/useClaudeIntegration';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { kyivFullDate } from '@/utils/backups';
import { errorMessage } from '@/utils/errors';
import { connectedLine, nearMoment, probeText, switchedLine, writesLine } from '@/utils/integrations';
import { roleName } from '@/utils/statuses';

/**
 * Інтеграції (builds.md §7): the organization's integrations, now Claude, for its owners. Claude is off until one turns
 * it on, which records who and when; it only reads until one allows changes too (asked first; who and when shown).
 * While it's on: Доступ (the user it reads as, the one who turned it on by default;
 * the ФОП it sees, all by default), Підключення (`claude mcp add …` for Claude Code, the JSON for Claude Desktop, to
 * copy, with the key, and a new key, which stops the old one), and Стан (Claude's last connection and call, and, on
 * the person's own computer, a probe of its Claude configs). Turning it off and a new key ask first.
 */
interface OrganizationUser {
    id: number;
    name: string;
    roles: string[];
}

/** The «who turned it on» choice of the acting user, which the API stores as null. */
const ENABLER = 0;

const accordion = provideAccordion();
const { profiles } = useCurrentProfile();
const { integration, error, loading, reload, connection, probe, busy, failure, configure, rekey, runProbe } = useClaudeIntegration();
const { data: users } = useLoader(async (signal) => (await api.get<Paginated<OrganizationUser>>('users', { page: { size: 100 } }, signal)).data, []);

const failureText = computed(() => (failure.value ? errorMessage(failure.value) : null));
const switched = computed(() => (integration.value ? switchedLine(integration.value) : null));
const writes = computed(() => (integration.value ? writesLine(integration.value) : null));

/* Changes: allowed after asking, taken back at once */
const writesOpen = ref(false);

async function writesTo(allowed: boolean): Promise<void> {
    if (allowed) {
        writesOpen.value = true;

        return;
    }

    await configure({ writes: false });
}

async function writesConfirmed(): Promise<void> {
    if (await configure({ writes: true })) {
        writesOpen.value = false;
    }
}

/* The switch: on at once, off after asking */
const disableOpen = ref(false);

async function switchTo(enabled: boolean): Promise<void> {
    if (enabled) {
        await configure({ enabled: true });

        return;
    }

    disableOpen.value = true;
}

async function disableConfirmed(): Promise<void> {
    if (await configure({ enabled: false })) {
        disableOpen.value = false;
    }
}

/* Доступ */
const form = reactive({ acting_user_id: ENABLER as number | null, mode: 'all' as 'all' | 'chosen', profile_ids: [] as number[] });
const MODES = computed(() => [
    { value: 'all' as const, label: t('settings.integrations.profilesAll') },
    { value: 'chosen' as const, label: t('settings.integrations.profilesChosen') },
]);

const userOptions = computed(() => [
    { value: ENABLER, label: t('settings.integrations.whoEnabled', { name: integration.value?.enabled_by?.name ?? '—' }) },
    ...(users.value ?? []).map((user) => ({ value: user.id, label: `${user.name} · ${user.roles.map(roleName).join(', ')}` })),
]);

const actingUserText = computed(() => {
    const chosen = integration.value?.acting_user_id;

    return chosen === null || chosen === undefined
        ? t('settings.integrations.whoEnabled', { name: integration.value?.enabled_by?.name ?? '—' })
        : (userOptions.value.find((option) => option.value === chosen)?.label ?? integration.value?.acting_user?.name ?? '—');
});

const profilesText = computed(() => (integration.value?.profile_ids
    ? t('settings.integrations.profilesChosenCount', { count: integration.value.profile_ids.length })
    : t('settings.integrations.profilesAll')));

const noneChosen = computed(() => form.mode === 'chosen' && form.profile_ids.length === 0);
const accessError = computed(() => (noneChosen.value ? t('settings.integrations.noneChosen') : busy.value === null ? failureText.value : null));

function openAccess(): void {
    form.acting_user_id = integration.value?.acting_user_id ?? ENABLER;
    form.mode = integration.value?.profile_ids ? 'chosen' : 'all';
    form.profile_ids = [...(integration.value?.profile_ids ?? [])];
}

function toggleProfile(id: number): void {
    form.profile_ids = form.profile_ids.includes(id) ? form.profile_ids.filter((item) => item !== id) : [...form.profile_ids, id];
}

async function saveAccess(): Promise<void> {
    if (noneChosen.value) {
        return;
    }

    const saved = await configure({
        acting_user_id: form.acting_user_id === ENABLER ? null : form.acting_user_id,
        profile_ids: form.mode === 'chosen' ? form.profile_ids : null,
    });

    if (saved) {
        accordion.open.value = null;
    }
}

/* Підключення */
const client = ref<'code' | 'desktop'>('code');
const CLIENTS = [
    { value: 'code' as const, label: 'Claude Code' },
    { value: 'desktop' as const, label: 'Claude Desktop' },
];
const config = computed(() => (connection.value ? (client.value === 'code' ? connection.value.claude_code : connection.value.claude_desktop) : ''));
const copied = ref(false);

async function copy(): Promise<void> {
    await navigator.clipboard.writeText(config.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

const notes = computed(() => (connection.value?.notes ?? []).map((note) => ({
    folder: t('settings.integrations.noteFolder'),
    server: t('settings.integrations.noteServer'),
    appimage: t('settings.integrations.noteAppimage'),
})[note]));

const rekeyOpen = ref(false);

async function rekeyConfirmed(): Promise<void> {
    if (await rekey()) {
        rekeyOpen.value = false;
    }
}
</script>

<template>
    <SubScreen :title="t('settings.integrations.title')" :back="{ name: 'more' }">
        <template #aside>
            <SideNote :text="t('settings.sideNotes.integrations')" />
        </template>

        <LoadState :loading="loading && !integration" :error="error" @retry="reload">
            <template v-if="integration">
                <section aria-label="Claude" class="flex flex-col gap-3 rounded-card border border-line bg-surface px-4 pt-3 pb-4">
                    <div class="flex min-h-10 items-center justify-between gap-3">
                        <h2 class="m-0 flex items-center gap-2.5 text-16 font-semibold">
                            <Icon name="plug" :size="22" />
                            Claude
                        </h2>
                        <span class="text-13 font-medium" :class="integration.enabled ? 'text-positive' : 'text-ink-3'">
                            {{ integration.enabled ? t('settings.integrations.on') : t('settings.integrations.off') }}
                        </span>
                    </div>
                    <p class="m-0 text-14 leading-[1.45] text-ink-2">{{ t('settings.integrations.claudeAbout') }}</p>
                    <SwitchCard
                        :model-value="integration.enabled"
                        :title="t('settings.integrations.enable')"
                        :sub="t('settings.integrations.offByDefault')"
                        :disabled="busy !== null"
                        @update:model-value="switchTo"
                    />
                    <p v-if="switched" class="m-0 text-13 text-ink-3">{{ switched }}</p>
                    <template v-if="integration.enabled">
                        <SwitchCard
                            :model-value="integration.writes_enabled"
                            :title="t('settings.integrations.writes')"
                            :sub="t('settings.integrations.writesHint')"
                            :disabled="busy !== null"
                            @update:model-value="writesTo"
                        />
                        <p v-if="writes" class="m-0 text-13 text-ink-3">{{ writes }}</p>
                    </template>
                    <p v-if="failureText && busy === null && !accordion.open.value" class="m-0 text-14 text-negative" role="alert">{{ failureText }}</p>
                </section>

                <template v-if="integration.enabled">
                    <AccordionGroup
                        id="access"
                        :title="t('settings.integrations.accessGroup')"
                        :saving="busy === 'access'"
                        :error="accessError"
                        @open="openAccess"
                        @save="saveAccess"
                    >
                        <ReadRow :label="t('settings.integrations.actingUser')" :value="actingUserText" />
                        <ReadRow :label="t('settings.integrations.profiles')" :value="profilesText" />
                        <template #edit>
                            <SelectField
                                v-model="form.acting_user_id"
                                :label="t('settings.integrations.actingUser')"
                                :options="userOptions"
                                :hint="t('settings.integrations.actingUserHint')"
                            />
                            <div class="flex flex-col gap-1.5">
                                <span class="text-13 font-medium text-ink-2">{{ t('settings.integrations.profiles') }}</span>
                                <SegmentedControl v-model="form.mode" :label="t('settings.integrations.profiles')" :options="MODES" />
                            </div>
                            <div v-if="form.mode === 'chosen'" class="flex flex-col gap-1">
                                <label v-for="item in profiles" :key="item.id" class="flex min-h-11 items-center gap-3">
                                    <input type="checkbox" class="size-5 accent-accent" :checked="form.profile_ids.includes(item.id)" @change="toggleProfile(item.id)">
                                    <span class="truncate text-15">{{ item.full_name }}</span>
                                </label>
                            </div>
                        </template>
                    </AccordionGroup>

                    <section :aria-label="t('settings.integrations.connectionGroup')" class="flex flex-col gap-3 rounded-card border border-line bg-surface px-4 pt-3 pb-4">
                        <h2 class="m-0 flex min-h-10 items-center text-16 font-semibold">{{ t('settings.integrations.connectionGroup') }}</h2>
                        <SegmentedControl v-model="client" :label="t('settings.integrations.connectionGroup')" :options="CLIENTS" />
                        <p class="m-0 text-13 leading-[1.45] text-ink-3">
                            {{ client === 'code' ? t('settings.integrations.claudeCodeHint') : t('settings.integrations.claudeDesktopHint') }}
                        </p>
                        <pre
                            v-if="connection"
                            class="m-0 max-h-72 overflow-auto rounded-card border border-line bg-paper p-3 font-mono text-12 leading-relaxed break-all whitespace-pre-wrap select-all"
                        >{{ config }}</pre>
                        <p v-for="note in notes" :key="note" class="m-0 flex gap-2 rounded-control bg-muted px-3.5 py-3 text-13 leading-[1.45] text-ink-2">
                            <Icon name="info" :size="18" class="shrink-0" />
                            <span>{{ note }}</span>
                        </p>
                        <div class="grid grid-cols-2 gap-2.5">
                            <SecondaryButton :disabled="!connection" @click="copy">
                                {{ copied ? t('settings.integrations.copied') : t('settings.integrations.copy') }}
                            </SecondaryButton>
                            <SecondaryButton :busy="busy === 'key'" :disabled="busy !== null" @click="rekeyOpen = true">{{ t('settings.integrations.newKey') }}</SecondaryButton>
                        </div>
                        <p class="m-0 text-13 text-ink-3">
                            {{ t('settings.integrations.keyValue', { hint: integration.key_hint ?? '', date: kyivFullDate(integration.key_created_at) }) }}
                            · {{ t('settings.integrations.keySecret') }}
                        </p>
                    </section>

                    <section :aria-label="t('settings.integrations.statusGroup')" class="flex flex-col gap-2.5 rounded-card border border-line bg-surface px-4 pt-3 pb-4">
                        <h2 class="m-0 flex min-h-10 items-center text-16 font-semibold">{{ t('settings.integrations.statusGroup') }}</h2>
                        <p class="m-0 text-15">{{ connectedLine(integration) }}</p>
                        <p v-if="integration.last_used_at" class="m-0 text-13 text-ink-3">
                            {{ t('settings.integrations.lastCall', { tool: integration.last_tool ?? '', when: nearMoment(integration.last_used_at) }) }}
                        </p>
                        <template v-if="integration.probe_available">
                            <SecondaryButton :busy="busy === 'probe'" :disabled="busy !== null" @click="runProbe">{{ t('settings.integrations.probe') }}</SecondaryButton>
                            <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.integrations.probeHint') }}</p>
                            <dl v-if="probe" class="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-14">
                                <dt class="text-ink-3">Claude Code</dt>
                                <dd class="m-0" :class="probe.claude_code.status === 'current' ? 'text-positive' : ''">{{ probeText(probe.claude_code.status) }}</dd>
                                <dt class="text-ink-3">Claude Desktop</dt>
                                <dd class="m-0" :class="probe.claude_desktop.status === 'current' ? 'text-positive' : ''">{{ probeText(probe.claude_desktop.status) }}</dd>
                            </dl>
                            <p v-if="probe && !probe.claude_command" class="m-0 text-13 text-ink-3">{{ t('settings.integrations.claudeCommandMissing') }}</p>
                        </template>
                        <p v-else class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.integrations.probeUnavailable') }}</p>
                    </section>
                </template>
            </template>
        </LoadState>

        <ConfirmSheet
            v-model:open="disableOpen"
            :title="t('settings.integrations.disableTitle')"
            :confirm-label="t('settings.integrations.disable')"
            destructive
            :busy="busy === 'switch'"
            :error="disableOpen ? failureText : null"
            @confirm="disableConfirmed"
        >
            <p class="m-0">{{ t('settings.integrations.disableText') }}</p>
        </ConfirmSheet>
        <ConfirmSheet
            v-model:open="writesOpen"
            :title="t('settings.integrations.writesTitle')"
            :confirm-label="t('settings.integrations.allowWrites')"
            :busy="busy === 'writes'"
            :error="writesOpen ? failureText : null"
            @confirm="writesConfirmed"
        >
            <p class="m-0">{{ t('settings.integrations.writesText', { name: integration?.acting_user?.name ?? '—' }) }}</p>
        </ConfirmSheet>
        <ConfirmSheet
            v-model:open="rekeyOpen"
            :title="t('settings.integrations.newKeyTitle')"
            :confirm-label="t('settings.integrations.newKey')"
            :busy="busy === 'key'"
            :error="rekeyOpen ? failureText : null"
            @confirm="rekeyConfirmed"
        >
            <p class="m-0">{{ t('settings.integrations.newKeyText') }}</p>
        </ConfirmSheet>
    </SubScreen>
</template>
