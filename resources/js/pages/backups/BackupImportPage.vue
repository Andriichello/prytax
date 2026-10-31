<script setup lang="ts">
import { computed } from 'vue';
import BackupImportFlow from '@/components/backups/BackupImportFlow.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';

/**
 * Відновити з архіву as a page of its own: Новий ФОП leads to it while the organization has no ФОП (Резервні копії
 * opens the same in place, on its ФОП tab). Back to Новий ФОП then, else to Резервні копії.
 */
const { profile } = useCurrentProfile();

const back = computed(() => (profile.value ? { name: 'backups' } : { name: 'profile-new' }));
</script>

<template>
    <SubScreen :title="t('settings.backups.import.title')" :back="back">
        <template #aside>
            <SideNote :text="t('settings.backups.import.sideNote')" />
        </template>

        <BackupImportFlow />
    </SubScreen>
</template>
