import type { Messages } from '@/i18n/types';

/**
 * Ukrainian texts of Облік, ЄСВ, the income book, fees, taxes and declarations.
 */
export default {

    /** Облік, Квартали */
    quarters: {
        groupBy: 'Групувати',
        none: 'Ні',
        showList: 'Показувати в списку',
        feesNet: 'Комісії й чисто',
        noReceipts: 'У {quarter} {year} надходжень немає.',
        countOldestFirst: '{count} · від старших',
        noInvoice: 'Без інвойсу',
        paidInFull: 'Оплачено повністю · {total}',
        advancesOtherIncome: 'Аванси й інший дохід',
        quarterCsv: 'CSV кварталу',
        yearCsv: 'CSV за рік',
    },

    /** A receipt of Облік */
    paymentRow: {
        refund: 'Повернення',
        toAccount: 'на {account}',
        net: 'Чисто',
    },

    /** The summary of a quarter */
    summary: {
        quarterSummary: 'Підсумок кварталу',
        incomeFor: 'Дохід за {quarter} {year}',
        incomeYearToDate: 'Дохід з початку року',
        taxOwed: '{tax} до сплати',
        ytdMinusPrevious: '{ytd} за рік − {previousYtd} за {quarter}',
        ytdMinusYearStart: '{ytd} за рік − {previousYtd} на початок року',
        crossCheckRate: 'Перевірка: {rate} від доходу кварталу',
        rateAboveCap: '{rate}, понад ліміт {excessRate},',
        crossCheckSum: 'Перевірка: сума по надходженнях',
        singleTaxYtd: 'ЄП за рік',
        militaryLevyYtd: 'ВЗ за рік',
        sscYtd: 'ЄСВ за рік',
        incomeCap: 'Ліміт доходу',
        excessTaxed: 'Перевищення ліміту {excess} оподатковується за {rate}: {tax}.',
    },

    /** Облік, ЄСВ */
    esv: {
        owed: 'Нараховано',
        paidOn: 'сплачено {date}',
    },

    /** Облік, Книга доходів */
    incomeBook: {
        current: 'поточний',
    },

    /** Комісії */
    fees: {
        intro: 'Скільки коштує шлях грошей від клієнта до гривні: комісії й курсовий спред.',
        noReceiptsTransfers: 'За {year} рік надходжень і переказів немає.',
        yearTotals: 'Підсумки року',
        lossShare: 'Втрати на комісіях і спреді — {percent} доходу.',
        netIncomeExplained:
            'Чистий дохід — це дохід мінус комісії, ЄП, ВЗ і ЄСВ; спред показано окремо, бо це втрата на обміні, а не комісія.',
        byQuarter: 'За кварталами',
        losses: 'Втрати',
        net: 'Чистий',
        byAccount: 'За рахунками',
        feesAmount: 'комісії {amount}',
        transfersOnly: 'лише перекази',
        byClient: 'За клієнтами',
        noClient: 'Без клієнта',
        byRoute: 'За маршрутами',
        feesWithoutRate: 'Без курсу НБУ не пораховано комісій переказів: {count}.',
    },

    /** Податки */
    taxes: {
        payment: 'Сплата',
        view: 'Подання',
        singleTaxAndLevyDue: 'ЄП + ВЗ · до {date}',
    },

    /** Податки by quarter */
    taxQuarter: {
        taxBalance: 'Сальдо {tax}',
        taxBalanceOn: 'Сальдо {tax} {date}',
        asOfYearEnd: 'на 31.12.{year}',
        asOfToday: 'на сьогодні',
        ofThisDue: 'З них {amount} за {quarter} — до {date}.',
        ofThisOverdueSince: 'З них {amount} за {quarter} — прострочено з {date}.',
        anotherDue: 'Ще {amount} за {quarter} — до {date}.',
        anotherOverdueSince: 'Ще {amount} за {quarter} — прострочено з {date}.',
        restOwedDue: 'Решта — нараховане за {quarter}, строк {date}.',
        owedDue: 'Нараховане за {quarter}, строк {date}.',
        awaitsConfirmation: 'Очікує підтвердження {amount}.',
        openingBalance: 'Вхідне сальдо на {date}',
        upcoming: 'майбутній',
        closed: 'закрито',
        dueInDays: 'до {date} · {days}',
        balance: 'Сальдо',
        pendingSent: 'Очікує: {amount} надіслано {date}',
        failedAmount: 'Не пройшло: {amount}',
        payAmount: 'Сплатити {amount}',
        sscExplained: 'ЄСВ нараховується щомісяця незалежно від доходу. Переплата переноситься в наступні квартали.',
        owedExplained:
            'Нараховано за квартал = {tax} з початку року мінус {tax} попереднього кварталу — та сама сума, що в декларації. Сальдо переноситься між кварталами й роками.',
    },

    /** Податки by month */
    taxMonths: {
        title: 'Податки за місяцями',
        sentAmount: '+{amount} надісл.',
        forQuarter: 'за кв.',
        ofItForQuarter: 'з них за кв. {amount}',
        sscEveryMonth: 'ЄСВ — за кожен місяць, крім звільнених.',
        owedExplained:
            'Нараховано за місяць — сума підказок його надходжень; до сплати за квартал береться його нарахування.',
        spreadExplained:
            'Сплати за квартал розносяться по місяцях з першого: кожен бере те, що ще винен, решта — останньому («за кв.»).',
        tickMeansClosed: 'Галочка — квартал закрито.',
    },

    /** Податки by receipt */
    taxReceipts: {
        sentOn: 'надіслано {date}',
        paid: 'сплачено',
        coveredPeriodPayment: 'покрито сплатою періоду',
        notPaid: 'не сплачено',
        payReceipt: 'Сплатити за цим надходженням',
        paymentsPeriod: 'Сплати за період',
        taxForPeriod: '{tax} за {period}',
        covers: '{date} · покриває {covers}',
        reconciliation: 'Звірка {quarter}',
        sumHints: 'Сума підказок',
        roundingNote:
            'Різниця в копійку — наслідок округлення по кожному надходженню. До сплати береться «Нараховано».',
    },

    /** Сплата податку */
    taxPayment: {
        title: 'Сплата податку',
        amountUah: 'Сума, ₴',
        sentIsPending: '«Надіслано» показується як очікуване й не зменшує борг',
        paidFromAccount: 'Сплачено з рахунку',
        notSpecified: 'Не вказувати',
        paymentDetails: 'Реквізити {tax}',
        copyAll: 'Копіювати все',
        amount: 'Сума',
        noPaymentDetails: 'Реквізитів {tax} для цього періоду немає. Додайте їх у налаштуваннях податків.',
        paymentOrderNumber: 'Номер платіжки',
        savePayment: 'Зберегти сплату',
        receiptOnly: 'ЄП і ВЗ сплачуються лише з надходження. ЄСВ — окремо, за місяць.',
        accruedFrom: '{rate} від {base} ₴',
        accruedFromReceipt: 'нараховано з надходження',
        paidOn: 'Дата сплати',
        primary: 'Основний',
        primaryHint: 'Основний — з «Податки й сповіщення». З валютного рахунку банк спершу продає валюту на ФОП-рахунок у UAH.',
        writtenOff: 'Списано, {currency}',
        writtenOffHint: 'Скільки списано з рахунку, — з виписки банку. Його курс не НБУ, тож суму вводите ви.',
        bankRate: 'Курс банку: 1 {currency} = {rate} ₴',
        esvTitle: 'Сплата ЄСВ',
        payBy: 'сплатити до {date}',
        alreadyPaidFor: 'За {month} уже сплачено {date} · {amount}',
        esvMinimum: 'Мінімальний внесок за місяць — {amount} ₴ (22 % від мінімальної зарплати). Можна більше.',
        deletePayment: 'Видалити сплату',
        deleteQuestion: 'Видалити сплату?',
        deleteExplained: 'Сплата зникне з обліку податків, а борг за неї знову зросте.',
    },
} satisfies Messages;
