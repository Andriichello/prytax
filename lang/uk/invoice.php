<?php

/*
 * Texts of the invoice PDF in Ukrainian (template v2 and later; v1 keeps its own): the labels, and the built-in texts
 * of the payment purpose and the act note (design.md §4.1), which a profile, a client or an invoice may replace. The
 * labels are written in normal case: the template upper-cases the title and the section labels.
 */
return [
    'labels' => [
        'title' => 'Інвойс',
        'number' => '№',
        'issued' => 'Дата',
        'due' => 'Сплатити до',
        'entrepreneur' => 'фізична особа-підприємець',
        'tax_id' => 'РНОКПП',
        'passport' => 'Паспорт',
        'bill_to' => 'Платник',
        'vat' => 'VAT',
        'client_tax_id' => 'Податковий номер',
        'period' => 'Період',
        'contract' => 'Договір',
        'contract_dated' => 'від :date',
        'col_no' => '#',
        'col_desc' => 'Опис',
        'col_qty' => 'К-сть',
        'col_price' => 'Ціна, :cur',
        'col_amount' => 'Сума, :cur',
        'subtotal' => 'Проміжний підсумок',
        'adjustment' => 'Коригування',
        'total' => 'Разом',
        'unit' => [
            'hour' => 'год',
            'fixed' => 'фікс.',
            'item' => 'шт.',
        ],
        'payment_details' => 'Платіжні реквізити',
        'beneficiary' => 'Отримувач',
        'bank' => 'Банк',
        'iban' => 'IBAN',
        'account_number' => 'Номер рахунку',
        'swift' => 'SWIFT / BIC',
        'currency' => 'Валюта',
        'payment_purpose' => 'Призначення платежу',
        'act_of_acceptance' => 'Акт приймання-передачі',
    ],

    'defaults' => [
        'payment_purpose' => 'Оплата за {services} згідно з інвойсом № {invoice.number} від {invoice.date}',
        'services' => 'послуги',
        'act_note' => 'Оплата цього інвойсу підтверджує належне надання послуг і є актом приймання-передачі наданих послуг. Додаткові підписи не потрібні.',
    ],
];
