import { createApp } from 'vue';
import { onUnauthenticated } from '@/api/client';
import App from '@/App.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { router } from '@/router';

const session = useSession();

/*
 * A 401 after signing in means the session ended on the server (it expired, the password changed, the account was
 * suspended): forget it and ask to sign in again, coming back to the same page.
 */
onUnauthenticated(() => {
    if (!session.isSignedIn.value) {
        return;
    }

    session.forget();
    useCurrentProfile().reset();

    const current = router.currentRoute.value;

    if (!current.meta.guest && !current.meta.public) {
        void router.replace({ name: 'login', query: { redirect: current.fullPath } });
    }
});

createApp(App).use(router).mount('#app');
