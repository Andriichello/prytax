<script setup lang="ts">
import { computed } from 'vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { startupError } from '@/router';
import { errorMessage } from '@/utils/errors';

/**
 * The app's frame: one centred column, at most 480 px wide (the designs are drawn at 390 px). When the app can't
 * start (no connection, the server failed), it says so with a retry instead of a blank page. The infinite lists stay
 * alive behind the records opened from them, so going back returns to the same place; another user starts afresh.
 */
const KEPT_ALIVE = ['InvoicesPage', 'TransfersPage'];

const { user } = useSession();
const sessionKey = computed(() => user.value?.id ?? 'guest');

const retry = (): void => window.location.reload();
</script>

<template>
    <div class="mx-auto min-h-dvh w-full max-w-120 bg-paper">
        <AuthLayout v-if="startupError">
            <div class="flex flex-col gap-4">
                <h1 class="m-0 text-22 font-semibold">{{ t('shell.startup.title') }}</h1>
                <p class="m-0 text-15 leading-normal text-ink-2" role="alert">{{ errorMessage(startupError) }}</p>
                <PrimaryButton @click="retry">{{ t('shell.startup.tryAgain') }}</PrimaryButton>
            </div>
        </AuthLayout>
        <RouterView v-else v-slot="{ Component }">
            <KeepAlive :key="sessionKey" :include="KEPT_ALIVE">
                <component :is="Component" />
            </KeepAlive>
        </RouterView>
    </div>
</template>
