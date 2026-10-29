<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue';
import { loadRouteLocation, type RouteLocationNormalizedLoaded, useRoute, useRouter } from 'vue-router';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PageRoute from '@/components/layout/PageRoute.vue';
import RecordPanel from '@/components/layout/RecordPanel.vue';
import Sidebar from '@/components/layout/Sidebar.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { panelBackground, startupError } from '@/router';
import { errorMessage } from '@/utils/errors';

/**
 * The app's frame. On mobile, one centred column, at most 480 px wide (the designs are drawn at 390 px); on desktop
 * (from 600 px, design.md §17; the sidebar a rail of icons below 1024 px), the sidebar and the page beside it, at
 * most 1440 px wide (the boards' width) and
 * centred on a wider screen, and a record's page in the panel on the right, over the page it was opened from
 * (`panelBackground`), which stays mounted under it. When the app can't start (no
 * connection, the server failed), it says so with a retry instead of a blank page. The infinite lists and Податки
 * stay alive behind the records opened from them, so going back returns to the same place, without loading again
 * unless something was saved meanwhile (`dataVersion`); another user, or another ФОП, starts afresh. The condition
 * sits on the KeepAlive, not on the page: a `v-if` gives its element the key 0, and KeepAlive caches by key, so every
 * page kept alive would share one place in the cache and come back as another one.
 * A screen for signed-in users shows only while someone is: signing out (or a lapsed session) leaves it before the
 * router reaches Вхід, rather than mounting it once more without a user, whose loaders would fail.
 */
/** The pages kept alive, by component name; their routes say so too (`meta.keepAlive`), for the scroll a tab restores. */
const KEPT_ALIVE = ['InvoicesPage', 'TransfersPage', 'TaxesPage'];

const route = useRoute();
const router = useRouter();
const { user } = useSession();
const { profileId } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

const sessionKey = computed(() => (user.value ? `${user.value.id}:${profileId.value ?? ''}` : 'guest'));

/** The desktop frame is for the signed-in screens; Вхід and the other open pages keep their own. */
const shell = computed(() => isDesktop.value && Boolean(user.value) && !route.meta.guest && !route.meta.public && !route.meta.bare);

/** The page under the panel, loaded (its component may not be, when the record was opened directly). */
const base = shallowRef<RouteLocationNormalizedLoaded | null>(null);

/**
 * The page last shown without a panel. A panel opened from it lies over it at once: waiting for it to load would
 * unmount the page (and the pages kept alive with it) for a moment, which loads it again and loses its scroll.
 */
let lastPage: RouteLocationNormalizedLoaded | null = null;

watch(() => router.currentRoute.value, (current) => {
    if (!current.meta.panel) {
        lastPage = current;
    }
}, { immediate: true });

watch(panelBackground, async (path) => {
    if (!path) {
        base.value = null;

        return;
    }

    if (lastPage?.fullPath === path) {
        base.value = lastPage;

        return;
    }

    const loaded = await loadRouteLocation(router.resolve(path));

    if (panelBackground.value === path) {
        base.value = loaded as RouteLocationNormalizedLoaded;
    }
}, { immediate: true });

const inPanel = computed(() => shell.value && Boolean(route.meta.panel));

/** Closing the panel goes back to its page: through the history when it came from there, else by a new entry. */
function closePanel(): void {
    const back = panelBackground.value ?? route.meta.panel?.of ?? '/';

    if (window.history.state?.back === back) {
        router.back();
    } else {
        void router.push(back);
    }
}

const retry = (): void => window.location.reload();
</script>

<template>
    <div :class="shell ? 'flex min-h-dvh bg-paper' : 'mx-auto min-h-dvh w-full max-w-120 bg-paper'">
        <AuthLayout v-if="startupError">
            <div class="flex flex-col gap-4">
                <h1 class="m-0 text-22 font-semibold">{{ t('shell.startup.title') }}</h1>
                <p class="m-0 text-15 leading-normal text-ink-2" role="alert">{{ errorMessage(startupError) }}</p>
                <PrimaryButton @click="retry">{{ t('shell.startup.tryAgain') }}</PrimaryButton>
            </div>
        </AuthLayout>
        <template v-else-if="shell">
            <div class="mx-auto flex w-full max-w-360">
                <Sidebar class="sticky top-0 self-start min-[1441px]:shadow-[-1px_0_0_var(--color-line)]" />
                <PageRoute :route="inPanel ? base : null">
                    <div class="min-w-0 flex-1">
                        <RouterView v-if="!inPanel || base" v-slot="{ Component }" :route="inPanel ? base ?? undefined : undefined">
                            <KeepAlive :key="sessionKey" :include="KEPT_ALIVE">
                                <component :is="Component" />
                            </KeepAlive>
                        </RouterView>
                    </div>
                </PageRoute>
            </div>
            <RecordPanel v-if="inPanel" :wide="route.meta.panel?.wide" :dialog="route.meta.panel?.dialog" @close="closePanel">
                <RouterView />
            </RecordPanel>
        </template>
        <RouterView v-else v-slot="{ Component, route: shown }">
            <KeepAlive v-if="user || shown.meta.guest || shown.meta.public" :key="sessionKey" :include="KEPT_ALIVE">
                <component :is="Component" />
            </KeepAlive>
        </RouterView>
    </div>
</template>
