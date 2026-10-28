<script setup lang="ts">
import { ref } from 'vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ProfileSwitchList from '@/components/profile/ProfileSwitchList.vue';
import NewPill from '@/components/ui/NewPill.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';

/**
 * ФОП організації (M-ProfileSwitch, design.md §16.1, §16.5): the organization's ФОП (`ProfileSwitchList`), active
 * first, under the count line, with the footnote on what switching does. «Новий» is for owners.
 */
const { can } = useSession();
const { isDesktop } = useBreakpoint();

const list = ref<InstanceType<typeof ProfileSwitchList> | null>(null);
</script>

<template>
    <SubScreen :title="isDesktop ? t('shell.profiles.title') : ''" :muted="!isDesktop" :subtitle="isDesktop ? list?.meta : undefined" :back="{ name: 'more' }">
        <template v-if="isDesktop && can('create', 'profiles')" #action>
            <NewPill :to="{ name: 'profile-new' }" />
        </template>

        <div v-if="!isDesktop" class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('shell.profiles.title') }}</h1>
                <NewPill v-if="can('create', 'profiles')" :to="{ name: 'profile-new' }" />
            </div>
            <p v-if="list?.meta" class="m-0 text-14 text-ink-3">{{ list.meta }}</p>
        </div>

        <ProfileSwitchList ref="list" />

        <p class="m-0 px-0.5 text-14 leading-[1.45] text-ink-3">{{ t('shell.profiles.footnote') }}</p>
    </SubScreen>
</template>
