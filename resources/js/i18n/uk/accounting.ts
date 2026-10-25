import type { Messages } from '@/i18n/types';

/**
 * Ukrainian texts of Облік, ЄСВ, the income book, fees, taxes and declarations.
 */
export default {

    /** Облік, Квартали */
    quarters: {
        feesNet: 'Комісії й чисто',
        noReceipts: 'У {quarter} {year} надходжень немає.',
        quarterCsv: 'CSV кварталу',
        yearCsv: 'CSV за рік',
        byMonth: 'По місяцях',
        byInvoice: 'По інвойсах',
        ungrouped: 'Без групування',
        summary: 'Підсумок кварталу',
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
        closed: 'закрито',
        dueBy: 'до {date}',
        accrued: 'Нараховано',
        paid: 'Сплачено',
        forMonth: 'за {month}',
        allTaxes: 'Усі податки',
        allStatuses: 'Усі статуси',
        summaryTitle: 'Підсумок за період',
        total: 'Усього податків',
        sent: 'Надіслано',
        failedNotCounted: '{amount} не пройшло (не враховано)',
        byMonth: 'Податки за місяцями',
        receivedOn: 'надійшло {date}',
        footnote: 'У кожному місяці: спершу несплачене (ЄП і ВЗ — у місяці надходження, ЄСВ — у місяці, за який він), далі «Не пройшов», «Надіслано» й «Сплачено». «Надіслано» борг не зменшує; «Не пройшов» ніде не враховується.',
        empty: 'Немає сплат за обраний період і податки.',
        filterTaxes: 'Податки',
        filterStatuses: 'Статус',
        taxesHint: 'Можна обрати кілька. Не обрано жодного — показуються всі.',
        statusesHint: 'Підсумок рахує лише обрані статуси. Не обрано жодного — показуються всі.',
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
