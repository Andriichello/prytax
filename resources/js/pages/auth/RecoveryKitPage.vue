<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import RecoveryKitPanel from '@/components/settings/RecoveryKitPanel.vue';
import { t } from '@/i18n';

/**
 * Набір відновлення: the desktop installation's key (builds.md §4.5), which the owner keeps away from the computer
 * before working, as the router's guard asks after registering; then the setup continues where it was going. Резервні
 * копії shows the same kit in its own tab.
 */
const route = useRoute();
const router = useRouter();

const redirect = computed(() => (typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'));
</script>

<template>
    <AuthLayout>
        <div class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.recoveryKit.title') }}</h1>
            <RecoveryKitPanel setup @done="router.replace(redirect)" />
        </div>
    </AuthLayout>
</template>
