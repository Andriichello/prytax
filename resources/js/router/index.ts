import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';

export type TabName = 'home' | 'invoices' | 'accounting' | 'taxes' | 'more';

declare module 'vue-router' {
    interface RouteMeta {
        /** The tab of the bottom bar, which a top-level screen belongs to. */
        tab?: TabName;
        /** Pages for signed-out people only (signing in): a signed-in user goes home. */
        guest?: boolean;
        /** Pages for anyone (the reset link, "not found"). */
        public?: boolean;
        /** The title of a page that isn't built yet. */
        title?: string;
    }
}

const comingSoon = () => import('@/pages/ComingSoonPage.vue');

/**
 * The SPA's paths. The API links to some of them (notifications' `action_route`, e-mails): `/invoices/{id}`,
 * `/accounts/{id}`, `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from=`, `/clients/{client}/contracts/{id}`,
 * `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
 * `/reset-password`. Change them together with the API.
 */
const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/pages/auth/LoginPage.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/pages/auth/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/auth/ResetPasswordPage.vue'), meta: { public: true } },

    { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue'), meta: { tab: 'home' } },
    { path: '/alerts', name: 'alerts', component: () => import('@/pages/AlertsPage.vue') },
    { path: '/overview', name: 'overview', component: () => import('@/pages/OverviewPage.vue') },

    { path: '/invoices', name: 'invoices', component: comingSoon, meta: { tab: 'invoices', title: 'Інвойси' } },
    { path: '/invoices/new', name: 'invoice-new', component: comingSoon, meta: { title: 'Новий інвойс' } },
    { path: '/invoices/:id(\\d+)', name: 'invoice', component: comingSoon, meta: { title: 'Інвойс' } },

    { path: '/accounting/:year(\\d{4})?/:quarter([1-4])?', name: 'accounting', component: comingSoon, meta: { tab: 'accounting', title: 'Облік' } },
    { path: '/taxes', name: 'taxes', component: comingSoon, meta: { tab: 'taxes', title: 'Податки' } },
    { path: '/more', name: 'more', component: comingSoon, meta: { tab: 'more', title: 'Ще' } },

    { path: '/profiles/new', name: 'profile-new', component: comingSoon, meta: { title: 'Новий ФОП' } },
    { path: '/settings/taxes', name: 'settings-taxes', component: comingSoon, meta: { title: 'Податки й сповіщення' } },

    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue'), meta: { public: true } },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
});

/**
 * Signed-out people may open only the sign-in pages; signed-in ones work in a profile, chosen before the first page.
 */
router.beforeEach(async (to) => {
    const { load } = useSession();
    const user = await load();

    if (to.meta.public) {
        return true;
    }

    if (to.meta.guest) {
        return user ? { name: 'home' } : true;
    }

    if (!user) {
        return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }

    await useCurrentProfile().ensure(user);

    return true;
});
