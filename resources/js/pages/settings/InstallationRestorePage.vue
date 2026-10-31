<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import InstallationRestoreFlow from '@/components/installation/InstallationRestoreFlow.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * Відновлення застосунку (builds.md §4.11): a full backup of another computer in this one's place, after comparing
 * them. Owners open it from Резервні копії; on a new computer, before anyone registered, Реєстрація leads to it
 * (`restore-full`), framed as the signed-out pages are, back to Реєстрація.
 */
const route = useRoute();
const guest = computed(() => route.name === 'restore-full');
</script>

<template>
    <div v-if="guest" class="min-h-dvh bg-paper">
        <div class="mx-auto flex max-w-[760px] flex-col gap-4 px-4 py-6">
            <RouterLink :to="{ name: 'register' }" class="flex size-11 items-center justify-center self-start rounded-full text-ink" :aria-label="t('shell.restore.backToRegister')">
                <Icon name="back" :size="22" />
            </RouterLink>
            <h1 class="m-0 text-22 font-semibold">{{ t('settings.fullRestore.title') }}</h1>
            <InstallationRestoreFlow />
        </div>
    </div>
    <SubScreen v-else :title="t('settings.fullRestore.title')" :back="{ name: 'backups' }">
        <InstallationRestoreFlow />
    </SubScreen>
</template>
