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
    }
}

/**
 * The SPA's paths. The API links to some of them (notifications' `action_route`, e-mails): `/invoices/{id}`,
 * `/accounts/{id}`, `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from=`, `/clients/{client}/contracts/{id}`,
 * `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
 * `/reset-password`, `/accept-invitation`. Change them together with the API.
 */
const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/pages/auth/LoginPage.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/pages/auth/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/auth/ResetPasswordPage.vue'), meta: { public: true } },
    { path: '/accept-invitation', name: 'accept-invitation', component: () => import('@/pages/auth/AcceptInvitationPage.vue'), meta: { public: true } },

    { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue'), meta: { tab: 'home' } },
    { path: '/alerts', name: 'alerts', component: () => import('@/pages/AlertsPage.vue') },
    { path: '/overview', name: 'overview', component: () => import('@/pages/OverviewPage.vue') },

    { path: '/invoices', name: 'invoices', component: () => import('@/pages/invoices/InvoicesPage.vue'), meta: { tab: 'invoices' } },
    { path: '/invoices/new', name: 'invoice-new', component: () => import('@/pages/invoices/InvoiceEditPage.vue') },
    { path: '/invoices/:id(\\d+)', name: 'invoice', component: () => import('@/pages/invoices/InvoiceDetailPage.vue') },
    { path: '/invoices/:id(\\d+)/edit', name: 'invoice-edit', component: () => import('@/pages/invoices/InvoiceEditPage.vue') },

    { path: '/payments/new', name: 'payment-new', component: () => import('@/pages/payments/PaymentFormPage.vue') },
    { path: '/payments/:id(\\d+)', name: 'payment', component: () => import('@/pages/payments/PaymentDetailPage.vue') },
    { path: '/payments/:id(\\d+)/edit', name: 'payment-edit', component: () => import('@/pages/payments/PaymentFormPage.vue') },
    { path: '/transfers', name: 'transfers', component: () => import('@/pages/transfers/TransfersPage.vue') },
    { path: '/transfers/new', name: 'transfer-new', component: () => import('@/pages/transfers/TransferFormPage.vue') },
    { path: '/transfers/:id(\\d+)', name: 'transfer', component: () => import('@/pages/transfers/TransferDetailPage.vue') },
    { path: '/transfers/:id(\\d+)/edit', name: 'transfer-edit', component: () => import('@/pages/transfers/TransferFormPage.vue') },

    { path: '/accounts', name: 'accounts', component: () => import('@/pages/accounts/AccountsPage.vue') },
    { path: '/accounts/new', name: 'account-new', component: () => import('@/pages/accounts/AccountFormPage.vue') },
    { path: '/accounts/:id(\\d+)', name: 'account', component: () => import('@/pages/accounts/AccountDetailPage.vue') },
    { path: '/accounts/:id(\\d+)/edit', name: 'account-edit', component: () => import('@/pages/accounts/AccountFormPage.vue') },
    { path: '/clients', name: 'clients', component: () => import('@/pages/clients/ClientsPage.vue') },
    { path: '/clients/new', name: 'client-new', component: () => import('@/pages/clients/ClientFormPage.vue') },
    { path: '/clients/:id(\\d+)', name: 'client', component: () => import('@/pages/clients/ClientDetailPage.vue') },
    { path: '/clients/:id(\\d+)/edit', name: 'client-edit', component: () => import('@/pages/clients/ClientFormPage.vue') },
    { path: '/clients/:clientId(\\d+)/contracts/new', name: 'contract-new', component: () => import('@/pages/clients/ContractFormPage.vue') },
    { path: '/clients/:clientId(\\d+)/contracts/:id(\\d+)', name: 'contract', component: () => import('@/pages/clients/ContractFormPage.vue') },

    { path: '/accounting/:year(\\d{4})?/:quarter([1-4])?', name: 'accounting', component: () => import('@/pages/accounting/AccountingPage.vue'), meta: { tab: 'accounting' } },
    { path: '/accounting/esv', name: 'accounting-esv', component: () => import('@/pages/accounting/EsvPage.vue'), meta: { tab: 'accounting' } },
    { path: '/accounting/income-book', name: 'income-book', component: () => import('@/pages/accounting/IncomeBookPage.vue'), meta: { tab: 'accounting' } },

    { path: '/taxes', name: 'taxes', component: () => import('@/pages/taxes/TaxesPage.vue'), meta: { tab: 'taxes' } },
    {
        path: '/taxes/:year(\\d{4})/:quarter([1-4])/payments',
        name: 'taxes-by-payment',
        redirect: (to) => ({ name: 'taxes', query: { year: to.params.year, quarter: to.params.quarter, view: 'receipts' } }),
    },
    { path: '/taxes/payments/new', name: 'tax-payment-new', component: () => import('@/pages/taxes/TaxPaymentFormPage.vue') },
    { path: '/taxes/declaration/:year(\\d{4})/:quarter([1-4])', name: 'declaration', component: () => import('@/pages/taxes/DeclarationPage.vue') },

    { path: '/more', name: 'more', component: () => import('@/pages/settings/MorePage.vue'), meta: { tab: 'more' } },
    { path: '/profiles/new', name: 'profile-new', component: () => import('@/pages/settings/ProfileFormPage.vue') },
    { path: '/settings/profile', name: 'settings-profile', component: () => import('@/pages/settings/ProfileFormPage.vue') },
    { path: '/settings/numbering', name: 'settings-numbering', component: () => import('@/pages/settings/NumberingSettingsPage.vue') },
    { path: '/settings/taxes', name: 'settings-taxes', component: () => import('@/pages/settings/TaxSettingsPage.vue') },
    { path: '/users', name: 'users', component: () => import('@/pages/settings/UsersPage.vue') },

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
