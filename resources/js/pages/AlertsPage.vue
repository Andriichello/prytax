<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AlertList, { type Shown } from '@/components/alert/AlertList.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * Сповіщення (M-Alerts): the profile's notifications (`AlertList`), all or the unread ones (`?show=unread`), under a
 * header with the way to their settings.
 */
const route = useRoute();
const router = useRouter();

const shown = computed<Shown>({
    get: () => (route.query.show === 'unread' ? 'unread' : 'all'),
    set: (value) => void router.replace({ query: value === 'all' ? {} : { show: value } }),
});
</script>

<template>
    <SubScreen :title="t('shell.alerts.notifications')">
        <template #action>
            <RouterLink
                :to="{ name: 'settings-taxes' }"
                :aria-label="t('shell.alerts.notificationSettings')"
                class="flex size-11 items-center justify-center text-ink hover:text-ink"
            >
                <Icon name="sliders" :size="22" />
            </RouterLink>
        </template>

        <AlertList v-model:shown="shown" />
    </SubScreen>
</template>
