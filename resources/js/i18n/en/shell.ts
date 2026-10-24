import type { Translation } from '@/i18n/types';
import type uk from '@/i18n/uk/shell';

/**
 * The English of `i18n/uk/shell.ts`.
 */
export default {
    startup: {
        title: 'Couldn’t open PryTax',
        tryAgain: 'Try again',
    },

    login: {
        email: 'Email',
        password: 'Password',
        rememberMe: 'Remember me',
        forgotPassword: 'Forgot your password?',
        signIn: 'Sign in',
        accessNote: 'Access is given by the organization’s owner.',
        accountantNote: 'An accountant sees every FOP of their practice.',
    },

    forgotPassword: {
        checkEmail: 'Check your email',
        sent:
            'If {email} belongs to an account, we’ve sent it a link to change the password. The link is valid for an hour.',
        backToSignIn: 'Back to sign-in',
        title: 'Reset your password',
        hint: 'We’ll email you a link to set a new password.',
        sendLink: 'Send link',
    },

    resetPassword: {
        passwordChanged: 'Password changed',
        changedHint: 'Sign in with your new password. The account’s other sessions have been ended.',
        linkIncomplete: 'The link is incomplete',
        openWholeLink: 'Open the whole link from the email, or ask for a new one.',
        sendNewLink: 'Send a new link',
        newPassword: 'New password',
        forEmail: 'for {email}',
        confirmPassword: 'Confirm password',
        savePassword: 'Save password',
    },

    invitation: {
        invitationAccepted: 'Invitation accepted',
        acceptedHint: 'Sign in with your email and the password you’ve just set.',
        openWholeLink: 'Open the whole link from the email, or ask the organization’s owner to invite you again.',
        title: 'You’re invited',
        hint: 'Set a password to join the organization.',
        join: 'Join',
    },

    changePassword: {
        title: 'Set your own password',
        explained: 'The password for {email} was set by someone else, so change it before you start.',
        currentPassword: 'Current password',
        confirmNewPassword: 'Confirm new password',
    },

    noProfile: {
        noFopsYet: 'No FOPs yet',
        explained: 'The organization’s owner needs to add a FOP before {name} can work with the records.',
        you: 'you',
        pageNotFound: 'Page not found',
        notFoundHint: 'The link may be outdated or contain a mistake.',
        goHome: 'Go to Home',
    },

    nav: {
        home: 'Home',
        invoices: 'Invoices',
        accounting: 'Accounting',
        taxes: 'Taxes',
        mainNavigation: 'Main navigation',
        back: 'Back',
        switchFop: '{name}, switch FOP',
        chooseFop: 'Choose a FOP',
        fopGroup: 'FOP · group {group}',
    },

    more: {
        language: 'Language',
        tagline: 'Income records, invoices and taxes for 3rd-group FOPs',
        more: 'More',
        moneyCounterparties: 'Money and counterparties',
        transfersFees: 'Transfers and fees',
        feesNetIncome: 'Fees and net income',
        clientsContracts: 'Clients and contracts',
        fopSettings: 'FOP settings',
        fopProfileDetails: 'FOP profile and details',
        organization: 'Organization',
        overviewAllFops: 'Overview of all FOPs',
        usersRoles: 'Users and roles',
        signOut: 'Sign out',
    },

    profileSwitcher: {
        searchFops: 'Search FOPs',
        searchPlaceholder: 'Search by name or tax ID',
        inactiveClosed: 'Inactive · closed {date}',
        correctiveDeclarationNeeded: 'Corrective declaration needed',
        taxId: 'Tax ID {taxId} · {alerts}',
        noNotifications: 'no notifications',
        overviewAll: 'Overview of all',
        addFop: 'Add a FOP',
    },

    alerts: {
        notifications: 'Notifications',
        notificationSettings: 'Notification settings',
        all: 'All',
        unreadFilter: 'Unread',
        funds: 'Funds',
        deadlines: 'Deadlines',
        readHint: 'A notification is marked as read when you tap it, its action or ✓.',
        noUnreadNotifications: 'No unread notifications.',
        noNotifications: 'No notifications: all is well.',
        showMore: 'Show more',
        unread: 'Unread',
        read: 'Read',
        markRead: 'Mark as read',
    },

    overview: {
        titleCount: 'Practice · {count}',
        activeFops: { one: '{count} active FOP', other: '{count} active FOPs' },
        title: 'Practice overview',
        previousQuarter: 'Previous quarter',
        nextQuarter: 'Next quarter',
        noActiveFops: 'No active FOPs in this quarter.',
        showInactive: 'Show inactive ({count})',
        incomeCapFor: 'Income cap {year}',
        ofTotal: 'of {total}',
        incomeIn: 'Income {quarter}',
        receivedOfInvoiced: 'received {received} of {invoiced} · {percent}',
        noInvoicesQuarter: 'No invoices this quarter.',
        taxableIncome: 'Taxable income: {amount}',
        taxesIn: 'Taxes {quarter}',
        paidAmount: 'paid {amount}',
        sentAmount: 'sent {amount}',
        noNotifications: 'No notifications',
        unreadAlerts: { one: '{count} unread notification', other: '{count} unread notifications' },
    },

    ui: {
        close: 'Close',
        loadingLabel: 'Loading',
        loadMoreFailed: 'Couldn’t load more.',
        notFound: 'Not found: the record was deleted or belongs to another FOP.',
        forbidden: 'You don’t have permission to view this.',
        loadFailed: 'Couldn’t load the data. Check your connection and try again.',
        tryAgain: 'Try again',
        copied: 'Copied',
        copyLabel: 'Copy {label}',
        previous: 'Previous',
        next: 'Next',
    },

    dateField: {
        placeholder: 'dd.mm.yyyy',
        invalid: 'Enter the date as dd.mm.yyyy',
        notBefore: 'No earlier than {date}',
        notAfter: 'No later than {date}',
        calendar: '{label}: calendar',
    },
} satisfies Translation<typeof uk>;
