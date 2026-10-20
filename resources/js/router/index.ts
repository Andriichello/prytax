import { ref } from 'vue';
import { START_LOCATION, createRouter, createWebHistory, type RouteParamsGeneric, type RouteRecordRaw } from 'vue-router';
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
        /** The permission a form needs (`[action, resource]`); without it, `denied` is shown instead. */
        can?: [string, string];
        /** Where people without the permission go: the record, or the list. */
        denied?: string;
        /** The API resource of the page's record, relative to its profile, so a link from outside finds its profile. */
        record?: (params: RouteParamsGeneric) => string;
    }
}

/**
 * The SPA's paths. The API links to some of them (notifications' `action_route`, e-mails): `/invoices/{id}`,
 * `/accounts/{id}`, `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from=`, `/clients/{client}/contracts/{id}`,
 * `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
 * `/reset-password`, `/accept-invitation`. Change them together with the API. Any path may name its ФОП
 * (`?profile=2`), which the app switches to.
 */
const record = (resource: string) => (params: RouteParamsGeneric): string => `${resource}/${params.id}`;

const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/pages/auth/LoginPage.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/pages/auth/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/auth/ResetPasswordPage.vue'), meta: { public: true } },
    { path: '/accept-invitation', name: 'accept-invitation', component: () => import('@/pages/auth/AcceptInvitationPage.vue'), meta: { public: true } },
    { path: '/change-password', name: 'change-password', component: () => import('@/pages/auth/ChangePasswordPage.vue') },
    { path: '/no-profile', name: 'no-profile', component: () => import('@/pages/NoProfilePage.vue') },

    { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue'), meta: { tab: 'home' } },
    { path: '/alerts', name: 'alerts', component: () => import('@/pages/AlertsPage.vue') },
    { path: '/overview', name: 'overview', component: () => import('@/pages/OverviewPage.vue') },

    { path: '/invoices', name: 'invoices', component: () => import('@/pages/invoices/InvoicesPage.vue'), meta: { tab: 'invoices' } },
    { path: '/invoices/new', name: 'invoice-new', component: () => import('@/pages/invoices/InvoiceEditPage.vue'), meta: { can: ['create', 'invoices'], denied: 'invoices' } },
    { path: '/invoices/:id(\\d+)', name: 'invoice', component: () => import('@/pages/invoices/InvoiceDetailPage.vue'), meta: { record: record('invoices') } },
    { path: '/invoices/:id(\\d+)/edit', name: 'invoice-edit', component: () => import('@/pages/invoices/InvoiceEditPage.vue'), meta: { can: ['update', 'invoices'], denied: 'invoice', record: record('invoices') } },

    { path: '/payments/new', name: 'payment-new', component: () => import('@/pages/payments/PaymentFormPage.vue'), meta: { can: ['create', 'payments'], denied: 'home' } },
    { path: '/payments/:id(\\d+)', name: 'payment', component: () => import('@/pages/payments/PaymentDetailPage.vue'), meta: { record: record('payments') } },
    { path: '/payments/:id(\\d+)/edit', name: 'payment-edit', component: () => import('@/pages/payments/PaymentFormPage.vue'), meta: { can: ['update', 'payments'], denied: 'payment', record: record('payments') } },
    { path: '/transfers', name: 'transfers', component: () => import('@/pages/transfers/TransfersPage.vue') },
    { path: '/transfers/new', name: 'transfer-new', component: () => import('@/pages/transfers/TransferFormPage.vue'), meta: { can: ['create', 'account-transfers'], denied: 'transfers' } },
    { path: '/transfers/:id(\\d+)', name: 'transfer', component: () => import('@/pages/transfers/TransferDetailPage.vue'), meta: { record: record('transfers') } },
    { path: '/transfers/:id(\\d+)/edit', name: 'transfer-edit', component: () => import('@/pages/transfers/TransferFormPage.vue'), meta: { can: ['update', 'account-transfers'], denied: 'transfer', record: record('transfers') } },

    { path: '/accounts', name: 'accounts', component: () => import('@/pages/accounts/AccountsPage.vue') },
    { path: '/accounts/new', name: 'account-new', component: () => import('@/pages/accounts/AccountFormPage.vue'), meta: { can: ['create', 'accounts'], denied: 'accounts' } },
    { path: '/accounts/:id(\\d+)', name: 'account', component: () => import('@/pages/accounts/AccountDetailPage.vue'), meta: { record: record('accounts') } },
    { path: '/accounts/:id(\\d+)/edit', name: 'account-edit', component: () => import('@/pages/accounts/AccountFormPage.vue'), meta: { can: ['update', 'accounts'], denied: 'account', record: record('accounts') } },
    { path: '/clients', name: 'clients', component: () => import('@/pages/clients/ClientsPage.vue') },
    { path: '/clients/new', name: 'client-new', component: () => import('@/pages/clients/ClientFormPage.vue'), meta: { can: ['create', 'clients'], denied: 'clients' } },
    { path: '/clients/:id(\\d+)', name: 'client', component: () => import('@/pages/clients/ClientDetailPage.vue'), meta: { record: record('clients') } },
    { path: '/clients/:id(\\d+)/edit', name: 'client-edit', component: () => import('@/pages/clients/ClientFormPage.vue'), meta: { can: ['update', 'clients'], denied: 'client', record: record('clients') } },
    {
        path: '/clients/:clientId(\\d+)/contracts/new',
        name: 'contract-new',
        component: () => import('@/pages/clients/ContractFormPage.vue'),
        meta: { can: ['create', 'contracts'], denied: 'client', record: (params) => `clients/${params.clientId}` },
    },
    {
        path: '/clients/:clientId(\\d+)/contracts/:id(\\d+)',
        name: 'contract',
        component: () => import('@/pages/clients/ContractFormPage.vue'),
        meta: { record: (params) => `clients/${params.clientId}/contracts/${params.id}` },
    },

    { path: '/accounting/:year(\\d{4})?/:quarter([1-4])?', name: 'accounting', component: () => import('@/pages/accounting/AccountingPage.vue'), meta: { tab: 'accounting' } },
    { path: '/accounting/esv', name: 'accounting-esv', component: () => import('@/pages/accounting/EsvPage.vue'), meta: { tab: 'accounting' } },
    { path: '/accounting/income-book', name: 'income-book', component: () => import('@/pages/accounting/IncomeBookPage.vue'), meta: { tab: 'accounting' } },
    { path: '/fees', name: 'fees', component: () => import('@/pages/accounting/FeesPage.vue') },

    { path: '/taxes', name: 'taxes', component: () => import('@/pages/taxes/TaxesPage.vue'), meta: { tab: 'taxes' } },
    {
        path: '/taxes/:year(\\d{4})/:quarter([1-4])/payments',
        name: 'taxes-by-payment',
        redirect: (to) => ({ name: 'taxes', query: { year: to.params.year, quarter: to.params.quarter, view: 'receipts' } }),
    },
    { path: '/taxes/payments/new', name: 'tax-payment-new', component: () => import('@/pages/taxes/TaxPaymentFormPage.vue'), meta: { can: ['create', 'tax-payments'], denied: 'taxes' } },
    { path: '/taxes/declaration/:year(\\d{4})/:quarter([1-4])', name: 'declaration', component: () => import('@/pages/taxes/DeclarationPage.vue') },

    { path: '/more', name: 'more', component: () => import('@/pages/settings/MorePage.vue'), meta: { tab: 'more' } },
    { path: '/profiles/new', name: 'profile-new', component: () => import('@/pages/settings/ProfileFormPage.vue'), meta: { can: ['create', 'profiles'], denied: 'more' } },
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

/** Why the app couldn't start (the session or the profiles didn't load): App shows it with a retry. */
export const startupError = ref<unknown>(null);

/**
 * Signed-out people may open only the sign-in pages; signed-in ones work in a profile, chosen before the first page.
 * A password set by someone else is replaced first (the API refuses everything else until then); an organization
 * without a ФОП starts with creating one, or tells why there's nothing to show.
 */
router.beforeEach(async (to, from) => {
    const { load, can } = useSession();

    try {
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

        if (user.is_password_update_required) {
            return to.name === 'change-password' ? true : { name: 'change-password', query: { redirect: to.fullPath } };
        }

        if (to.name === 'change-password') {
            return { name: 'home' };
        }

        const current = useCurrentProfile();
        const profile = await current.ensure(user);

        startupError.value = null;

        // A link naming its ФОП switches to it (unless it isn't this user's), and the URL drops the name
        if (profile && to.query.profile !== undefined) {
            const named = Number(to.query.profile);
            const { profile: _named, ...query } = to.query;

            if (current.profiles.value.some((item) => item.id === named)) {
                await current.select(named);
            }

            return { path: to.path, query, hash: to.hash, replace: true };
        }

        // A record opened from outside the app (another tab, an e-mail) may belong to another ФОП
        if (profile && from === START_LOCATION && to.meta.record) {
            const owner = await current.locate(to.meta.record(to.params));

            if (owner !== null && owner !== profile.id) {
                await current.select(owner);
            }
        }

        if (!profile) {
            const start = can('create', 'profiles') ? 'profile-new' : 'no-profile';

            return to.name === start ? true : { name: start };
        }

        if (to.name === 'no-profile') {
            return { name: 'home' };
        }

        // A form, which the role can't save, shows its record (or the list) instead
        if (to.meta.can && !can(...to.meta.can)) {
            const id = to.params.clientId ?? to.params.id;

            return { name: to.meta.denied ?? 'home', params: id === undefined ? {} : { id } };
        }

        return true;
    } catch (error) {
        startupError.value = error;

        return false;
    }
});

/*
 * A page's code, which can't be loaded (the app was deployed anew meanwhile), is loaded with the whole page instead.
 */
router.onError((error: unknown, to) => {
    const message = error instanceof Error ? error.message : String(error);

    if (/dynamically imported module|Importing a module script failed|module script/i.test(message)) {
        window.location.assign(to.fullPath);
    }
});
