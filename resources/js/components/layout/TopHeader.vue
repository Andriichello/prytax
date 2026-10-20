<script setup lang="ts">
import { ref } from 'vue';
import ProfileSwitcherSheet from '@/components/profile/ProfileSwitcherSheet.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';

/**
 * The header of the top-level screens: the current ФОП's chip, which opens the switcher, and a round action on the
 * right (the `action` slot).
 */
const { profile } = useCurrentProfile();

const switching = ref(false);
</script>

<template>
    <header class="flex items-center justify-between gap-2 px-4 pt-3 pb-1">
        <button
            type="button"
            class="flex min-h-11 min-w-0 items-center gap-2.5 rounded-3xl border border-line bg-surface py-1 pr-3 pl-1 text-left text-ink"
            :aria-label="profile ? `${profile.short_name}, змінити ФОП` : 'Оберіть ФОП'"
            @click="switching = true"
        >
            <Avatar :initials="profile?.initials ?? '?'" variant="dark" :size="34" />
            <span class="flex min-w-0 flex-col leading-[1.2]">
                <span class="truncate text-14 font-semibold">{{ profile?.short_name ?? 'Оберіть ФОП' }}</span>
                <span class="text-11 text-ink-3">ФОП · {{ profile?.tax_group ?? 3 }} група</span>
            </span>
            <Icon name="chevronDown" :size="18" />
        </button>
        <slot name="action" />
    </header>

    <ProfileSwitcherSheet v-model:open="switching" />
</template>
