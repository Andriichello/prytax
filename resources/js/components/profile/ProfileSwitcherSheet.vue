<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import type { Profile } from '@/api/types';
import Avatar from '@/components/ui/Avatar.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { formatDate } from '@/utils/dates';
import { countOf, NOTIFICATIONS } from '@/utils/plural';

/**
 * "Оберіть ФОП": search the organization's profiles and switch the app to one of them. Opening it loads the profiles
 * again, so their notification counts and correction flags are current.
 */
const open = defineModel<boolean>('open', { required: true });

const router = useRouter();
const { can } = useSession();
const { profiles, profileId, select, loadProfiles } = useCurrentProfile();

const search = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        void loadProfiles().catch(() => undefined);
    }
});

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return profiles.value;
    }

    return profiles.value.filter((profile) => profile.full_name.toLowerCase().includes(term)
        || (profile.tax_id ?? profile.tax_id_masked ?? '').includes(term));
});

function caption(profile: Profile): { text: string; alarming: boolean } {
    if (!profile.is_active) {
        return { text: profile.closed_at ? `Неактивний · закрито ${formatDate(profile.closed_at)}` : 'Неактивний', alarming: false };
    }

    if (profile.needs_correction) {
        return { text: 'Потрібна уточнююча декларація', alarming: true };
    }

    const unread = profile.unread_alerts_count ?? 0;

    return {
        text: `РНОКПП ${profile.tax_id_masked} · ${unread ? countOf(unread, NOTIFICATIONS) : 'без сповіщень'}`,
        alarming: false,
    };
}

async function choose(profile: Profile): Promise<void> {
    await select(profile.id);
    open.value = false;
    search.value = '';
    await router.push({ name: 'home' });
}

async function go(name: string): Promise<void> {
    open.value = false;
    await router.push({ name });
}
</script>

<template>
    <BottomSheet v-model:open="open" title="Оберіть ФОП">
        <div class="relative">
            <label for="profile-search" class="sr-only">Пошук ФОП</label>
            <Icon name="search" :size="20" class="pointer-events-none absolute top-3.5 left-3.5 text-ink-3" />
            <input
                id="profile-search"
                v-model="search"
                type="search"
                placeholder="Пошук за ПІБ або РНОКПП"
                class="h-12 w-full rounded-control border border-line-input bg-surface pr-3.5 pl-11 text-16 placeholder:text-ink-3"
            >
        </div>

        <div class="flex flex-col divide-y divide-divider overflow-hidden rounded-card border border-line bg-surface">
            <button
                v-for="profile in shown"
                :key="profile.id"
                type="button"
                :aria-current="profile.id === profileId ? 'true' : undefined"
                class="flex items-center gap-3 px-3.5 py-3 text-left"
                :class="[
                    profile.id === profileId ? 'bg-accent-soft' : '',
                    profile.is_active ? 'text-ink' : 'text-ink-3',
                ]"
                @click="choose(profile)"
            >
                <Avatar
                    :initials="profile.initials"
                    :variant="profile.id === profileId ? 'dark' : profile.is_active ? 'light' : 'dashed'"
                />
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="text-15" :class="profile.id === profileId ? 'font-semibold' : 'font-medium'">
                        {{ profile.full_name }}
                    </span>
                    <span
                        class="text-12"
                        :class="caption(profile).alarming ? 'text-negative' : profile.id === profileId ? 'text-ink-2' : 'text-ink-3'"
                    >
                        {{ caption(profile).text }}
                    </span>
                </span>
                <Icon v-if="profile.id === profileId" name="check" :size="22" class="text-accent" />
            </button>
            <p v-if="shown.length === 0" class="m-0 px-3.5 py-4 text-14 text-ink-3">Нікого не знайдено.</p>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <button
                type="button"
                class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium"
                @click="go('overview')"
            >
                <Icon name="overview" :size="18" />
                Огляд усіх
            </button>
            <button
                v-if="can('create', 'profiles')"
                type="button"
                class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium"
                @click="go('profile-new')"
            >
                <Icon name="plus" :size="18" />
                Додати ФОП
            </button>
        </div>
    </BottomSheet>
</template>
