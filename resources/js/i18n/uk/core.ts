import type { Messages } from '@/i18n/types';

/**
 * Ukrainian texts of the shared helpers: words of every screen, statuses, the names of taxes, kinds of accounts
 * and movements, roles, counts, quarters, the days of the week, errors of requests.
 */
export default {
    /** Words of many screens: actions, field labels, the common nouns */
    common: {
        save: 'Зберегти',
        delete: 'Видалити',
        cancel: 'Скасувати',
        month: 'Місяць',
        quarter: 'Квартал',
        year: 'Рік',
        currency: 'Валюта',
        period: 'Період',
        date: 'Дата',
        note: 'Примітка',
        name: 'Назва',
        status: 'Статус',
        total: 'Разом',
        number: 'Номер',
        amountInCurrency: 'Сума, {currency}',
        active: 'Активний',
        inactive: 'Неактивний',
        chooseAccount: 'Оберіть рахунок',
        yesDelete: 'Так, видалити',
        asOf: 'На дату',
        asOfDate: 'на {date}',
        optional: "Необов'язково",
        plusAdd: '+ Додати',
        add: 'Додати',
        edit: 'Змінити',
        done: 'Готово',
        retry: 'Повторити',
        loading: 'Завантаження…',
        new: 'Новий',
        saveChanges: 'Зберегти зміни',
        moreActions: 'Інші дії',
        fromDate: 'з {date}',
        untilDate: 'до {date}',
        previousYear: 'Попередній рік',
        nextYear: 'Наступний рік',
        nothingFound: 'Нікого не знайдено.',
        overdue: 'прострочено',
        overdueSince: 'прострочено з {date}',
        client: 'Клієнт',
        clients: 'Клієнти',
        contract: 'Договір',
        project: 'Проєкт',
        workType: 'Тип робіт',
        invoice: 'Інвойс',
        address: 'Адреса',
        tax: 'Податок',
        recipient: 'Отримувач',
        receipts: 'Надходження',
        receipt: 'Надходження',
        paid: 'Сплачено',
    },

    /** Statuses of invoices */
    invoiceStatus: {
        draft: 'Чернетка',
        ready: 'Готовий',
        sent: 'Надіслано',
        paid: 'Оплачено',
        cancelled: 'Скасовано',
    },

    /** Severities of notifications */
    alertSeverity: {
        info: 'Інфо',
        warning: 'Увага',
        error: 'Терміново',
    },

    /** Statuses of declarations */
    declarationStatus: {
        none: 'Не подано',
        filled: 'Заповнено',
        submitted: 'Подано',
        accepted: 'Прийнято',
        rejected: 'Відхилено',
        corrected: 'Потрібна уточнююча',
    },

    /** Statuses of tax payments */
    taxPaymentStatus: {
        sent: 'Надіслано',
        paid: 'Сплачено',
        failed: 'Не пройшов',
    },

    /** The short names of the taxes */
    taxes: {
        singleTax: 'ЄП',
        militaryLevy: 'ВЗ',
        socialContribution: 'ЄСВ',
    },

    /** Kinds of accounts */
    accountKinds: {
        platform: 'Платформа',
        fopBank: 'ФОП-рахунок в Україні',
        foreignBank: 'Іноземний банк',
        personal: 'Особистий',
    },

    /** Kinds of accounts, as groups of a list */
    accountGroups: {
        platform: 'Платформи',
        fopBank: 'ФОП-рахунки в Україні',
        foreignBank: 'Іноземні банки',
        personal: 'Особисті',
    },

    /** Kinds of an account's movements */
    movementKinds: {
        bankFee: 'Комісія банку',
        expense: 'Витрата',
        ownerWithdrawal: 'Зняття власником',
        interest: 'Відсотки',
        other: 'Інший рух',
    },

    /** Roles, inside a sentence */
    roles: {
        owner: 'власник',
        accountant: 'бухгалтер',
        viewer: 'перегляд',
    },

    /** Counts with their word, by the plural categories of `Intl.PluralRules` */
    counts: {
        invoices: { one: '{count} інвойс', few: '{count} інвойси', many: '{count} інвойсів' },
        payments: { one: '{count} надходження', few: '{count} надходження', many: '{count} надходжень' },
        contracts: { one: '{count} договір', few: '{count} договори', many: '{count} договорів' },
        days: { one: '{count} день', few: '{count} дні', many: '{count} днів' },
        transfers: { one: '{count} переказ', few: '{count} перекази', many: '{count} переказів' },
    },

    /** Quarters, short */
    quarters: {
        q1: 'I кв.',
        q2: 'II кв.',
        q3: 'III кв.',
        q4: 'IV кв.',
    },

    /** Quarters, in full */
    quarterNames: {
        q1: 'I квартал',
        q2: 'II квартал',
        q3: 'III квартал',
        q4: 'IV квартал',
    },

    /** Errors of requests, by their status */
    errors: {
        sessionEnded: 'Сесія завершилась. Увійдіть знову.',
        forbidden: 'Недостатньо прав для цієї дії.',
        notFound: 'Не знайдено.',
        conflict: 'Запис змінився або заблокований. Оновіть сторінку.',
        sessionExpired: 'Сесія застаріла. Оновіть сторінку.',
        checkForm: 'Перевірте дані форми.',
        tooManyAttempts: 'Забагато спроб. Зачекайте хвилину і спробуйте знову.',
        somethingWentWrong: 'Щось пішло не так. Спробуйте ще раз.',
        offline: 'Не вдалося зв’язатися із сервером. Перевірте з’єднання і спробуйте ще раз.',
    },

    /** Messages, which the API sends in English only (see `utils/errors.ts`) */
    apiMessages: {
        accountSuspended: 'Обліковий запис призупинено. Зверніться до власника організації.',
        passwordUpdateRequired: 'Змініть пароль, щоб продовжити роботу.',
        invitationInvalid:
            'Посилання із запрошення недійсне, застаріле або вже використане. Попросіть запросити вас знову.',
        severalOrganizations: 'Ця пошта є в кількох організаціях. Зверніться до власника організації.',
    },
} satisfies Messages;
