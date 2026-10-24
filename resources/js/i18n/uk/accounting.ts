import type { Messages } from '@/i18n/types';

/**
 * Ukrainian texts of Облік, ЄСВ, the income book, fees, taxes and declarations.
 */
export default {
    /** The tabs of Облік */
    tabs: {
        incomeBook: 'Книга доходів',
        sections: 'Розділи обліку',
    },

    /** Облік, Квартали */
    quarters: {
        exportQuarterCsv: 'Експорт кварталу в CSV',
        notTracked: 'Не відстежується',
        outsideRecords: 'Квартал поза обліком цього ФОП',
        declarationDue: 'Декларація до {date}',
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
        overpaid: 'Переплата',
        monthlyMinimum: '{percent} % мінзарплати ({minWage}) = {monthly} на місяць. Генерується незалежно від доходу.',
        sscByMonth: 'ЄСВ за місяцями',
        quarterDue: '{quarter} · до {date}',
        paidOn: 'сплачено {date}',
        annualDeclarationAppendix: 'річна декларація, додаток 1',
        exempt: 'звільнено',
        notTracked: 'не відстежується',
        notPaid: 'Не сплачено',
        paySsc: 'Сплатити ЄСВ за {quarter}',
        payAboveMinimum:
            'Можна сплатити більше мінімуму (до 15 мінзарплат): переплата зараховується в наступні квартали.',
        tapToExempt: 'Місяць, звільнений від сплати, позначте дотиком.',
        sscForMonth: 'ЄСВ · {month} {year}',
        exemptPayment: 'Звільнено від сплати',
        reason: 'Підстава',
        reasonPlaceholder: 'Напр., воєнний стан, 2022–2024',
        exemptMonthOwes: 'Звільнений місяць нічого не нараховує.',
    },

    /** Облік, Книга доходів */
    incomeBook: {
        intro:
            'Облік доходів у довільній формі (п. 296.1 ПКУ). Сума за місяць — з уже округлених надходжень; повернення клієнтам зменшують дохід місяця повернення.',
        incomeUah: 'Дохід, ₴',
        ytd: 'Наростаючим',
        current: 'поточний',
        export: 'Експорт',
        yearRecords: 'Облік доходів за рік',
        allReceiptsTaxes: 'Усі надходження та податки',
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
        declarationFor: 'Декларація за {quarter} {year}',
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
        allocate: 'Зарахувати на',
        noReceiptsQuarter: 'Надходжень у кварталі немає',
        remainingFor: 'Залишок {tax} за {period}',
        receiptOf: 'надходження {date}',
        theReceipt: 'надходження',
        amountUah: 'Сума, ₴',
        sentIsPending: '«Надіслано» показується як очікуване й не зменшує борг',
        paidFromAccount: 'Сплачено з рахунку',
        notSpecified: 'Не вказувати',
        personalAccount: '{name} · особистий',
        accountHint: 'За замовчуванням із налаштувань · лише активні рахунки в UAH',
        paymentDetails: 'Реквізити {tax}',
        copyAll: 'Копіювати все',
        amount: 'Сума',
        noPaymentDetails: 'Реквізитів {tax} для цього періоду немає. Додайте їх у налаштуваннях податків.',
        paymentOrderNumber: 'Номер платіжки',
        savePayment: 'Зберегти сплату',
    },

    /** Декларація */
    declaration: {
        taxPeriod: 'Податковий період',
        annualDeclaration: 'Річна декларація за {quarter} {year}',
        filedOn: 'подано {date}',
        declarationStatus: 'Статус декларації',
        correctiveFiled: 'Уточнюючу подано',
        incomeChangedAfterFiling:
            'Після подання декларації доходи або податки цього року змінено. Подайте уточнюючу декларацію і позначте її поданою.',
        deadlines: 'Строки',
        fileDeclaration: 'Подати декларацію',
        correctiveDeclarationNeeded: 'потрібна уточнююча декларація',
        filed: 'подано',
        weekdayIn: '{weekday} · через {days}',
        weekdayToday: '{weekday} · сьогодні',
        dueDatePassed: 'строк минув {date}',
        paySingleTax: 'Сплатити ЄП і ВЗ',
        paySsc: 'Сплатити ЄСВ',
        figures: 'Показники для декларації',
        incomeYearToDate: 'Дохід наростаючим з 01.01',
        singleTax: 'Єдиний податок',
        militaryLevy: 'Військовий збір',
        owedYearToDate: 'Нараховано з початку року',
        declaredFor: 'Задекларовано за {quarter}',
        declaredEarlier: 'Задекларовано раніше',
        owedFor: 'До сплати за {quarter}',
        sscAppendix1: 'ЄСВ, додаток 1',
        lockedOnceFiled:
            'Після позначки «Подано» квартал блокується. Зміни в ньому потребуватимуть примітки й позначать цей і наступні квартали року як «потрібна уточнююча».',
        notes: 'Примітки',
        acknowledgmentNumberComment: 'Номер квитанції, коментар',
        quarterNotTracked: 'Цей квартал не відстежується, тож статус декларації не змінюється.',
        fileAfterQuarterEnds: 'Подати можна після закінчення кварталу.',
        markFiled: 'Позначити поданою',
        filedAgain: 'Подано знову',
    },
} satisfies Messages;
