import { reactive, ref } from 'vue';
import { START_LOCATION, createRouter, createWebHistory, type RouteParamsGeneric, type RouteRecordRaw } from 'vue-router';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';

export type TabName = 'home' | 'invoices' | 'accounting' | 'taxes' | 'more';

declare module 'vue-router' {
    interface RouteMeta {
        /** The tab of the bottom bar, which a top-level screen belongs to. */
        tab?: TabName;
        /** Kept alive by App (its component is in `KEPT_ALIVE`), so a tab coming back to it restores its scroll. */
        keepAlive?: boolean;
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
        /**
         * On desktop, a record's page opens in the panel over the page it came from (design.md §17.1): `of` is the page
         * under it when it is opened directly; `wide` is the invoice editor's 760 px; `dialog` a centred dialog instead
         * (Інвойс для надходження).
         */
        panel?: { of: string; wide?: boolean; dialog?: boolean };
        /** A page of the sidebar on desktop: its header has no back arrow. */
        section?: boolean;
    }
}

/**
 * The SPA's paths. The API links to some of them (notifications' `action_route`, e-mails): `/invoices/{id}`,
 * `/accounts/{id}`, `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from=`, `/clients/{client}/contracts/{id}`,
 * `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
 * `/reset-password`, `/accept-invitation`. Change them together with the API. Any path may name its ФОП
 * (`?profile=2`), which the app switches to. The screens, which the «Minimal» phase hides (the practice overview, ЄСВ,
 * the income book, the declaration; design.md §16.1), redirect to Облік or Податки, so their links still lead somewhere.
 */
const record = (resource: string) => (params: RouteParamsGeneric): string => `${resource}/${params.id}`;

/**
 * Where the pages kept alive were scrolled when left, by route name, with the address they had: a tab, which comes
 * back to the same address, comes back to the same place (going back restores it through the history anyway).
 */
const keptScroll = new Map<string, { path: string; top: number }>();

const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/pages/auth/LoginPage.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/pages/auth/ForgotPasswordPage.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/auth/ResetPasswordPage.vue'), meta: { public: true } },
    { path: '/accept-invitation', name: 'accept-invitation', component: () => import('@/pages/auth/AcceptInvitationPage.vue'), meta: { public: true } },
    { path: '/change-password', name: 'change-password', component: () => import('@/pages/auth/ChangePasswordPage.vue') },
    { path: '/no-profile', name: 'no-profile', component: () => import('@/pages/NoProfilePage.vue') },

    { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue'), meta: { tab: 'home' } },
    { path: '/alerts', name: 'alerts', component: () => import('@/pages/AlertsPage.vue') },
    { path: '/overview', name: 'overview', redirect: { name: 'accounting' } },

    { path: '/invoices', name: 'invoices', component: () => import('@/pages/invoices/InvoicesPage.vue'), meta: { tab: 'invoices', keepAlive: true } },
    { path: '/invoices/new', name: 'invoice-new', component: () => import('@/pages/invoices/InvoiceEditPage.vue'), meta: { can: ['create', 'invoices'], denied: 'invoices', panel: { of: '/invoices', wide: true } } },
    { path: '/invoices/:id(\\d+)', name: 'invoice', component: () => import('@/pages/invoices/InvoiceDetailPage.vue'), meta: { record: record('invoices'), panel: { of: '/invoices' } } },
    { path: '/invoices/:id(\\d+)/edit', name: 'invoice-edit', component: () => import('@/pages/invoices/InvoiceEditPage.vue'), meta: { can: ['update', 'invoices'], denied: 'invoice', record: record('invoices'), panel: { of: '/invoices', wide: true } } },

    { path: '/payments/pick', name: 'payment-pick', component: () => import('@/pages/payments/PaymentInvoicePickPage.vue'), meta: { can: ['create', 'payments'], denied: 'accounting', panel: { of: '/accounting', dialog: true } } },
    { path: '/payments/new', name: 'payment-new', component: () => import('@/pages/payments/PaymentFormPage.vue'), meta: { can: ['create', 'payments'], denied: 'home', panel: { of: '/accounting' } } },
    { path: '/payments/:id(\\d+)', name: 'payment', component: () => import('@/pages/payments/PaymentDetailPage.vue'), meta: { record: record('payments'), panel: { of: '/accounting' } } },
    { path: '/payments/:id(\\d+)/edit', name: 'payment-edit', component: () => import('@/pages/payments/PaymentFormPage.vue'), meta: { can: ['update', 'payments'], denied: 'payment', record: record('payments'), panel: { of: '/accounting' } } },
    { path: '/transfers', name: 'transfers', component: () => import('@/pages/transfers/TransfersPage.vue'), meta: { keepAlive: true, section: true } },
    { path: '/transfers/new', name: 'transfer-new', component: () => import('@/pages/transfers/TransferFormPage.vue'), meta: { can: ['create', 'account-transfers'], denied: 'transfers', panel: { of: '/transfers' } } },
    { path: '/transfers/:id(\\d+)', name: 'transfer', component: () => import('@/pages/transfers/TransferDetailPage.vue'), meta: { record: record('transfers'), panel: { of: '/transfers' } } },
    { path: '/transfers/:id(\\d+)/edit', name: 'transfer-edit', component: () => import('@/pages/transfers/TransferFormPage.vue'), meta: { can: ['update', 'account-transfers'], denied: 'transfer', record: record('transfers'), panel: { of: '/transfers' } } },

    { path: '/accounts', name: 'accounts', component: () => import('@/pages/accounts/AccountsPage.vue'), meta: { section: true } },
    { path: '/accounts/new', name: 'account-new', component: () => import('@/pages/accounts/AccountFormPage.vue'), meta: { can: ['create', 'accounts'], denied: 'accounts' } },
    { path: '/accounts/:id(\\d+)', name: 'account', component: () => import('@/pages/accounts/AccountDetailPage.vue'), meta: { record: record('accounts') } },
    { path: '/accounts/:id(\\d+)/edit', name: 'account-edit', component: () => import('@/pages/accounts/AccountFormPage.vue'), meta: { can: ['update', 'accounts'], denied: 'account', record: record('accounts') } },
    { path: '/clients', name: 'clients', component: () => import('@/pages/clients/ClientsPage.vue'), meta: { section: true } },
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
    { path: '/accounting/esv', name: 'accounting-esv', redirect: (to) => ({ name: 'taxes', query: { year: to.query.year } }) },
    { path: '/accounting/income-book', name: 'income-book', redirect: { name: 'accounting' } },
    { path: '/books', name: 'books', component: () => import('@/pages/books/BooksPage.vue'), meta: { section: true } },
    { path: '/fees', name: 'fees', component: () => import('@/pages/accounting/FeesPage.vue'), meta: { section: true } },

    { path: '/taxes', name: 'taxes', component: () => import('@/pages/taxes/TaxesPage.vue'), meta: { tab: 'taxes', keepAlive: true } },
    {
        path: '/taxes/:year(\\d{4})/:quarter([1-4])/payments',
        name: 'taxes-by-payment',
        redirect: (to) => ({ name: 'taxes', query: { year: to.params.year, quarters: to.params.quarter } }),
    },
    { path: '/taxes/payments/new', name: 'tax-payment-new', component: () => import('@/pages/taxes/TaxPaymentFormPage.vue'), meta: { can: ['create', 'tax-payments'], denied: 'taxes', panel: { of: '/taxes' } } },
    { path: '/taxes/payments/:id(\\d+)', name: 'tax-payment', component: () => import('@/pages/taxes/TaxPaymentFormPage.vue'), meta: { record: record('tax-payments'), panel: { of: '/taxes' } } },
    { path: '/taxes/esv/new', name: 'esv-payment-new', component: () => import('@/pages/taxes/EsvPaymentFormPage.vue'), meta: { can: ['create', 'tax-payments'], denied: 'taxes', panel: { of: '/taxes' } } },
    { path: '/taxes/esv/:id(\\d+)', name: 'esv-payment', component: () => import('@/pages/taxes/EsvPaymentFormPage.vue'), meta: { record: record('tax-payments'), panel: { of: '/taxes' } } },
    {
        path: '/taxes/declaration/:year(\\d{4})/:quarter([1-4])',
        name: 'declaration',
        redirect: (to) => ({ name: 'taxes', query: { year: to.params.year, quarters: to.params.quarter } }),
    },

    { path: '/more', name: 'more', component: () => import('@/pages/settings/MorePage.vue'), meta: { tab: 'more' } },
    { path: '/profiles', name: 'profiles', component: () => import('@/pages/settings/ProfileSwitchPage.vue') },
    { path: '/profiles/new', name: 'profile-new', component: () => import('@/pages/settings/ProfileFormPage.vue'), meta: { can: ['create', 'profiles'], denied: 'more' } },
    { path: '/settings/profile', name: 'settings-profile', component: () => import('@/pages/settings/ProfileFormPage.vue'), meta: { section: true } },
    { path: '/settings/numbering', name: 'settings-numbering', component: () => import('@/pages/settings/NumberingSettingsPage.vue'), meta: { section: true } },
    { path: '/settings/invoice-document', name: 'settings-invoice-document', component: () => import('@/pages/settings/InvoiceDocumentSettingsPage.vue'), meta: { section: true } },
    { path: '/settings/taxes', name: 'settings-taxes', component: () => import('@/pages/settings/TaxSettingsPage.vue'), meta: { section: true } },
    { path: '/users', name: 'users', component: () => import('@/pages/settings/UsersPage.vue'), meta: { section: true } },
    { path: '/settings/backups', name: 'settings-backups', component: () => import('@/pages/settings/BackupsPage.vue'), meta: { can: ['viewAny', 'backup-runs'], denied: 'more', section: true } },

    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue'), meta: { public: true } },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, _from, savedPosition) => {
        if (savedPosition) {
            return savedPosition;
        }

        const kept = to.meta.keepAlive ? keptScroll.get(String(to.name)) : undefined;

        return kept && kept.path === to.fullPath ? { top: kept.top } : { top: 0 };
    },
});

/**
 * Where each tab's own screen was left (its address with the filters), so the tab bar returns to it as it was: the
 * route of a tab's screen is named after the tab. Signing in, and switching the ФОП, start afresh.
 */
export const tabPaths = reactive<Partial<Record<TabName, string>>>({});

/** The ФОП, whose places `tabPaths` and `keptScroll` hold. */
let placesOf: number | null = null;

function forgetPlaces(): void {
    keptScroll.clear();
    (Object.keys(tabPaths) as TabName[]).forEach((tab) => delete tabPaths[tab]);
}

router.beforeEach((_to, from) => {
    if (from.meta.keepAlive) {
        keptScroll.set(String(from.name), { path: from.fullPath, top: window.scrollY });
    }
});

router.afterEach((to, _from, failure) => {
    if (failure) {
        return;
    }

    const profileId = useCurrentProfile().profileId.value;

    if (to.meta.guest || profileId !== placesOf) {
        forgetPlaces();
        placesOf = profileId;
    }

    if (!to.meta.guest && to.meta.tab && to.name === to.meta.tab) {
        tabPaths[to.meta.tab] = to.fullPath;
    }
});

/**
 * The page under the record panel of the desktop layout (design.md §17.1): the page a record was opened from, kept
 * while one record leads to another, or the record's own list (`meta.panel.of`) when it was opened directly. Null
 * off a record. The mobile layout ignores it.
 */
export const panelBackground = ref<string | null>(null);

router.afterEach((to, from, failure) => {
    if (failure) {
        return;
    }

    if (!to.meta.panel) {
        panelBackground.value = null;
    } else if (!(from.meta.panel && panelBackground.value)) {
        panelBackground.value = from !== START_LOCATION && from.matched.length > 0 && !from.meta.guest && !from.meta.public
            ? from.fullPath
            : to.meta.panel.of;
    }
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
