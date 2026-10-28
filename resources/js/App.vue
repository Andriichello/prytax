<script setup lang="ts">
import { computed } from 'vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { startupError } from '@/router';
import { errorMessage } from '@/utils/errors';

/**
 * The app's frame: one centred column, at most 480 px wide (the designs are drawn at 390 px). When the app can't
 * start (no connection, the server failed), it says so with a retry instead of a blank page. The infinite lists and
 * Податки stay alive behind the records opened from them, so going back returns to the same place, without loading
 * again unless something was saved meanwhile (`dataVersion`); another user, or another ФОП, starts afresh. The condition sits on the
 * KeepAlive, not on the page: a `v-if` gives its element the key 0, and KeepAlive caches by key, so every page kept
 * alive would share one place in the cache and come back as another one.
 * A screen for signed-in users shows only while someone is: signing out (or a lapsed session) leaves it before the
 * router reaches Вхід, rather than mounting it once more without a user, whose loaders would fail.
 */
/** The pages kept alive, by component name; their routes say so too (`meta.keepAlive`), for the scroll a tab restores. */
const KEPT_ALIVE = ['InvoicesPage', 'TransfersPage', 'TaxesPage'];

const { user } = useSession();
const { profileId } = useCurrentProfile();
const sessionKey = computed(() => (user.value ? `${user.value.id}:${profileId.value ?? ''}` : 'guest'));

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
        <RouterView v-else v-slot="{ Component, route }">
            <KeepAlive v-if="user || route.meta.guest || route.meta.public" :key="sessionKey" :include="KEPT_ALIVE">
                <component :is="Component" />
            </KeepAlive>
        </RouterView>
    </div>
</template>
