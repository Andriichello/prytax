import type { Messages } from '@/i18n/types';

/**
 * Ukrainian texts of clients and their contracts.
 */
export default {
    /** Клієнти */
    list: {
        contractEnds: 'договір до {date}',
        searchClients: 'Пошук клієнта',
        nameAbbreviation: 'Назва або абревіатура',
        noClientsYet: 'Клієнтів ще немає.',
        addClient: 'Додати клієнта',
        outstanding: 'до оплати',
        hideInactive: 'Сховати неактивних',
        showInactive: 'Показати неактивних ({count})',
    },

    /** Клієнт: payments, contracts, projects, work types and details */
    detail: {
        ended: 'Завершено',
        notYetEffect: 'Ще не діє',
        inEffect: 'Діє',
        typeRatePerHour: '{type} {rate} / год',
        ratePerHour: '{rate} / год',
        noRates: 'Ставок немає',
        inactive: 'неактивний',
        paymentsIn: 'Оплати в {currency}',
        paidAllTime: 'Сплачено за весь час',
        paidIn: 'Сплачено у {year}',
        invoicedNotPaid: 'Виставлено й не сплачено',
        allPaid: 'Усе оплачено',
        noPayments: 'Оплат від клієнта ще не було.',
        invoicesCount: 'Інвойси клієнта ({count})',
        contracts: 'Договори',
        numberSign: '№ {number}',
        openEnded: 'безстроковий',
        noContractNote: 'Без договору ціни позицій вводяться вручну.',
        projects: 'Проєкти',
        noProjects: 'Проєктів немає.',
        workTypes: 'Типи робіт',
        noWorkTypes: 'Типів робіт немає: ставка договору одна для всіх позицій.',
        clientDetails: 'Реквізити клієнта',
        taxNumber: 'Податковий номер',
        invoiceEmail: 'Email для інвойсів',
        invoiceNote: 'Примітка в інвойсі',
        deactivateClient: 'Деактивувати клієнта',
        activateClient: 'Активувати клієнта',
        notDeletedNote: 'Клієнтів не видаляють — історія інвойсів зберігається',
    },

    /** A project or a work type of a client */
    item: {
        newProject: 'Новий проєкт',
        newWorkType: 'Новий тип робіт',
        code: 'Код',
        codeHint: 'Коротко, друкується в позиціях інвойсу',
    },

    /** Клієнт — новий або редагування */
    form: {
        latinLettersDigits: 'Лише латинські літери й цифри.',
        newClient: 'Новий клієнт',
        nameHint: 'Так клієнт називається в інвойсах і списках',
        abbreviation: 'Абревіатура',
        abbreviationHint:
            'Префікс номерів інвойсів: {abbreviation}-РРРР-ММ-ДД-n. Латинські літери й цифри, своя в кожного клієнта ФОП.',
        legalName: 'Юридична назва',
        country: 'Країна',
        invoiceAddress: 'Адреса для інвойсу',
        addressLine: 'Адреса, рядок {line}',
        addAddressLine: '+ Ще рядок адреси',
        city: 'Місто',
        stateRegion: 'Штат / регіон',
        postalCode: 'Поштовий індекс',
        taxDetails: 'Податкові дані',
        invoiceNoteHint: 'Друкується під інвойсом цього клієнта',
        invoiceDefaults: 'Інвойси за замовчуванням',
        currencyHint: 'Валюта нових договорів та інвойсів клієнта',
        notPrinted: 'Не друкується',
    },

    /** Договір */
    contract: {
        default: 'За замовчуванням',
        newContract: 'Новий договір',
        validFrom: 'Діє з',
        validUntil: 'Діє до',
        emptyOpenEnded: 'Порожньо — безстроковий',
        paymentTermsDays: 'Оплата, днів',
        itemDescriptionTemplate: 'Шаблон опису позиції',
        invoicePreview: 'Так виглядатиме в інвойсі',
        hourlyRates: 'Погодинні ставки',
        addRate: '+ Ставка',
        rateByPeriodEnd: 'Ставка підставляється в позицію за датою кінця періоду',
        rate: 'Ставка',
        duplicateRate: 'Ставка цього типу робіт з цієї дати вже є.',
        perHour: 'За годину, {currency}',
        subject: 'Предмет (послуги)',
        subjectHint: 'Підставляється в призначення платежу замість {placeholder}',
    },
} satisfies Messages;
