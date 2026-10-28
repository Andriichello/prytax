<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Profile } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import ProfileSwitchRow from '@/components/profile/ProfileSwitchRow.vue';
import Icon from '@/components/ui/Icon.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadState from '@/components/ui/LoadState.vue';
import NewPill from '@/components/ui/NewPill.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';

/**
 * ФОП організації (M-ProfileSwitch, design.md §16.1, §16.5): the organization's ФОП, active first, each with its
 * status line; the search filters them by name or by the digits of the tax number. Tapping one makes it current for
 * the whole app and lands on Головна; the current one carries a check on its avatar. «Новий» is for owners.
 */
const router = useRouter();
const { can } = useSession();
const { profileId, select } = useCurrentProfile();

const search = ref('');
const switching = ref(false);

const { data: profiles, loading, error, reload } = useLoader(
    (signal) => api.all<Profile>('profiles', { append: ['unread_alerts_count', 'status'] }, signal),
    [],
);

const digits = (text: string): string => text.replace(/\D/g, '');

const shown = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    const queryDigits = digits(query);

    return (profiles.value ?? []).filter((profile) => !query
        || profile.full_name.toLocaleLowerCase().includes(query)
        || (queryDigits.length > 0 && digits(profile.tax_id ?? profile.tax_id_masked).includes(queryDigits)));
});

const groups = computed(() => [
    { key: 'active', title: t('shell.profiles.active'), items: shown.value.filter((profile) => profile.is_active) },
    { key: 'inactive', title: t('shell.profiles.inactive'), items: shown.value.filter((profile) => !profile.is_active) },
].filter((group) => group.items.length > 0));

const meta = computed(() => {
    const all = profiles.value ?? [];
    const active = all.filter((profile) => profile.is_active).length;
    const inactive = all.length - active;

    return [
        t('core.counts.activeProfiles', { count: active }),
        ...(inactive ? [t('core.counts.inactiveProfiles', { count: inactive })] : []),
    ].join(' · ');
});

async function choose(profile: Profile): Promise<void> {
    if (switching.value) {
        return;
    }

    switching.value = true;

    try {
        await select(profile.id);
        await router.push({ name: 'home' });
    } finally {
        switching.value = false;
    }
}
</script>

<template>
    <SubScreen title="" muted :back="{ name: 'more' }">
        <div class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('shell.profiles.title') }}</h1>
                <NewPill v-if="can('create', 'profiles')" :to="{ name: 'profile-new' }" />
            </div>
            <p v-if="profiles" class="m-0 text-14 text-ink-3">{{ meta }}</p>
        </div>

        <div class="relative">
            <label for="profile-search" class="sr-only">{{ t('shell.profiles.searchLabel') }}</label>
            <Icon name="search" :size="20" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-ink-3" />
            <input
                id="profile-search"
                v-model="search"
                type="search"
                autocomplete="off"
                :placeholder="t('shell.profiles.search')"
                class="h-13 w-full rounded-control border border-line-input bg-surface pr-3.5 pl-11 text-16 placeholder:text-ink-3"
            >
        </div>

        <LoadState :loading="loading && !profiles" :error="error" @retry="reload">
            <EmptyState v-if="profiles && groups.length === 0" :text="t('shell.profiles.nothingFound')" />

            <section v-for="group in groups" :key="group.key" class="flex flex-col gap-2">
                <SectionTitle>
                    {{ group.title }}
                    <template #aside><span class="num text-ink-2">{{ group.items.length }}</span></template>
                </SectionTitle>
                <div class="flex flex-col divide-y divide-divider overflow-hidden rounded-card border border-line bg-surface">
                    <ProfileSwitchRow
                        v-for="profile in group.items"
                        :key="profile.id"
                        :profile="profile"
                        :current="profile.id === profileId"
                        :disabled="switching"
                        @choose="choose(profile)"
                    />
                </div>
            </section>

            <p class="m-0 px-0.5 text-14 leading-[1.45] text-ink-3">{{ t('shell.profiles.footnote') }}</p>
        </LoadState>
    </SubScreen>
</template>
