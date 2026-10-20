import { ApiError } from '@/api/client';

/**
 * Ukrainian texts of the API's messages, which reach people. The API answers in English until it gets Ukrainian
 * translations; unknown messages fall back to a text by the status.
 */
const MESSAGES: Record<string, string> = {
    'These credentials do not match our records.': 'Неправильна пошта або пароль.',
    'This account is suspended.': 'Обліковий запис призупинено. Зверніться до власника організації.',
    'Too Many Attempts.': 'Забагато спроб. Зачекайте хвилину і спробуйте знову.',
    'The email field is required.': 'Вкажіть електронну пошту.',
    'The email field must be a valid email address.': 'Перевірте адресу електронної пошти.',
    'The password field is required.': 'Вкажіть пароль.',
    'This password reset token is invalid.': 'Посилання для зміни пароля недійсне або застаріло.',
    'We can\'t find a user with that email address.': 'Користувача з такою поштою не знайдено.',
    'A generated invoice needs at least one item.': 'Додайте хоча б одну позицію.',
    'An external invoice keeps its own number: type it.': 'Зовнішній інвойс має власний номер: введіть його.',
    'An external invoice needs its uploaded PDF as the primary document.': 'Завантажте PDF зовнішнього інвойсу.',
    'Another invoice has this number already.': 'Інвойс з таким номером уже є.',
    'Only a draft can be frozen.': 'Зафіксувати можна лише чернетку.',
    'Only a ready invoice can return to its draft.': 'Повернути в чернетку можна лише готовий інвойс.',
    'The charge type isn\'t one of the client\'s.': 'Цей тип робіт належить іншому клієнту.',
    'The client is required.': 'Оберіть клієнта.',
    'The contract isn\'t one of the client\'s.': 'Цей договір належить іншому клієнту.',
    'The description is required.': 'Вкажіть опис.',
    'The invoice has no document yet: freeze it, or upload its PDF.': 'Документа ще немає: зафіксуйте інвойс або завантажте PDF.',
    'The invoice has receipts, so it can\'t return to its draft.': 'Інвойс має надходження, тож повернути його в чернетку не можна.',
    'The invoice was changed by someone else. Reload it and try again.': 'Інвойс змінив хтось інший. Оновіть сторінку і спробуйте знову.',
    'The issue date is required.': 'Вкажіть дату виставлення.',
    'The period can\'t end before it starts.': 'Період не може закінчуватися раніше, ніж почався.',
    'The price is required: the contract has no hourly rate in the invoice\'s currency.': 'Вкажіть ціну: договір не має ставки у валюті інвойсу.',
    'The primary document of a frozen invoice can\'t be deleted.': 'Основний документ зафіксованого інвойсу не можна видалити.',
    'The primary document of a frozen invoice can\'t change.': 'Основний документ зафіксованого інвойсу не можна замінити.',
    'The project isn\'t one of the client\'s.': 'Цей проєкт належить іншому клієнту.',
    'The receiving account is inactive.': 'Цей рахунок неактивний.',
    'The receiving account is required.': 'Оберіть рахунок для оплати.',
    'The received amount is required, when the account\'s currency differs.': 'Вкажіть, скільки зараховано: валюта рахунку інша.',
    'Receipts are recorded for frozen invoices only: ready, sent or paid.': 'Надходження записують лише для зафіксованих інвойсів.',
    'The invoice is required.': 'Оберіть інвойс.',
    'The account is required.': 'Оберіть рахунок.',
    'The account is inactive, so receipts can\'t land on it.': 'Рахунок неактивний, на нього не можна зарахувати кошти.',
    'A personal account isn\'t a business one, so receipts can\'t land on it.': 'Особистий рахунок не приймає надходжень ФОП.',
    'The receipt was transferred on or refunded, so its account and received amount can\'t change.': 'Кошти вже переказано або повернено, тож рахунок і суму зарахування змінити не можна.',
    'An FX gain isn\'t client money, so it isn\'t refunded.': 'Курсовий дохід не є коштами клієнта, його не повертають.',
    'A receipt is allocated twice.': 'Одне надходження розподілено двічі.',
    'A receipt is not found.': 'Надходження не знайдено.',
    'A transfer can\'t be sent after it arrived.': 'Дата відправлення не може бути пізніше за дату зарахування.',
    'A transfer moves money between two different accounts of the profile.': 'Переказ має бути між двома різними рахунками.',
    'More can\'t arrive than was sent in the same currency.': 'В одній валюті не може прийти більше, ніж надіслано.',
    'The allocations exceed the amount sent.': 'Розподілено більше, ніж надіслано.',
    'The fee of a transfer is less than the amount sent.': 'Комісія має бути меншою за суму переказу.',
    'Tax payments reference the FX gain of the transfer, so its gain can\'t go away.': 'Сплати податків посилаються на курсовий дохід цього переказу.',
    'РНОКПП is 10 digits.': 'РНОКПП має 10 цифр.',
    'The passport is two letters and six digits, or the nine digits of an ID card.': 'Паспорт — дві літери й шість цифр, або дев’ять цифр ID-картки.',
    'Another ФОП of the organization has this tax id.': 'Інший ФОП організації вже має цей податковий номер.',
    'ЄСВ is allocated to a month or a quarter, not to a receipt.': 'ЄСВ сплачують за місяць або квартал, а не за надходження.',
    'A declaration is submitted after its quarter ends.': 'Декларацію подають після завершення кварталу.',
    'An FX gain follows its transfer, so it is changed through the transfer.': 'Курсовий дохід змінюється разом із переказом.',
    'An FX gain follows its transfer, so it is deleted with the transfer.': 'Курсовий дохід видаляється разом із переказом.',
    'A paid tax payment can\'t be dated in the future.': 'Сплачений платіж не може мати дату в майбутньому.',
    'FX gains are recorded by transfers.': 'Курсовий дохід записується переказом.',
    'One wire covers invoices of one currency.': 'Одне надходження може покривати інвойси лише в одній валюті.',
    'Other records reference it, so it can\'t be deleted. Deactivate it instead.': 'Цей запис уже використано в інших, тож видалити його не можна. Зробіть його неактивним.',
    'The client of the counter is not found.': 'Клієнта цього лічильника не знайдено.',
    'The date of the payment is required.': 'Вкажіть дату сплати.',
    'The month is required.': 'Оберіть місяць.',
    'The quarter is required.': 'Оберіть квартал.',
    'The year is required.': 'Вкажіть рік.',
    'The receipt is not found.': 'Надходження не знайдено.',
    'The requisites of the tax aren\'t entered yet.': 'Реквізити для сплати цього податку ще не внесено.',
    'A request with this Idempotency-Key is in progress.': 'Запит уже обробляється. Зачекайте кілька секунд.',
    'Password update is required to use your account.': 'Змініть пароль, щоб продовжити роботу.',
    'The invitation link is invalid, has expired or has been used.': 'Посилання із запрошення недійсне, застаріле або вже використане. Попросіть запросити вас знову.',
    'The email is used in several organizations, so choose one of them.': 'Ця пошта є в кількох організаціях. Зверніться до власника організації.',
    'The password field confirmation does not match.': 'Паролі не збігаються.',
    'The email has already been taken.': 'Користувач з такою поштою вже є.',
};

/**
 * Messages with values in them: the pattern, and the Ukrainian text made of its groups.
 */
const PATTERNS: [RegExp, (...groups: string[]) => string][] = [
    [/^The invoice has (\S+) (\w{3}) of receipts: .*$/, (amount, currency) => `Інвойс має надходження на ${amount} ${currency}: перенесіть їх на інвойс, що його замінює, або поверніть, перш ніж скасувати.`],
    [/^The receipts cover (\S+) of (\S+) (\w{3}), so the invoice isn't paid yet\.$/, (paid, total, currency) => `Надходження покривають ${paid} з ${total} ${currency}, тож інвойс ще не оплачено.`],
    [/^The receiving account must be in (\w{3})\.$/, (currency) => `Рахунок для оплати має бути в ${currency}.`],
    [/^Another invoice has the number (.+) already\.$/, (number) => `Інвойс з номером ${number} уже є.`],
    [/^The invoice is (\w+) already\.$/, () => 'Статус інвойсу вже змінено. Оновіть сторінку.'],
    [/^The NBU rate of (\w{3}) on (\S+) is unavailable\. .*$/, (currency, date) => `НБУ не має курсу ${currency} на ${date}. Введіть курс вручну.`],
    [/^Quarter (\d) of (\d{4}) is locked by its submitted declaration\. .*$/, (quarter, year) => `${quarter}-й квартал ${year} уже задекларовано. Опишіть уточнення, щоб змінити його.`],
    [/^Quarter (\d) of (\d{4}) is locked by its submitted declaration, which counts quarter (\d) too\. .*$/, (later, year) => `Декларацію за ${later}-й квартал ${year} уже подано, а вона враховує і цей. Опишіть уточнення.`],
    [/^The receipt is in (\w{3}), so it belongs to an invoice in \w{3} only\.$/, (currency) => `Надходження в ${currency}, тож належить лише інвойсу в ${currency}.`],
    [/^The receipt was refunded (\S+) (\w{3}), so its amount can't be less\.$/, (amount, currency) => `З надходження вже повернено ${amount} ${currency}, менше бути не може.`],
    [/^The receipt is referenced by (.+), so it can't be deleted\.$/, () => 'Надходження вже використано в переказах, поверненнях або сплатах податків, тож видалити його не можна.'],
    [/^Refunds can't exceed the receipt: (\S+) (\w{3}) is left to refund\.$/, (left, currency) => `Повернення не може перевищувати надходження: лишилося ${left} ${currency}.`],
    [/^The refund leaves from an account in (\w{3})\.$/, (currency) => `Повернення має йти з рахунку в ${currency}.`],
    [/^A refund can't precede its receipt of (\S+)\.$/, (date) => `Повернення не може бути раніше за надходження ${date}.`],
    [/^A receipt of (\S+) can't be moved by an earlier transfer\.$/, (date) => `Надходження ${date} не можна переказати раніше за цю дату.`],
    [/^The receipt holds only (\S+) (\w{3}) on (.+)\.$/, (amount, currency, account) => `На рахунку ${account} від цього надходження лишилося лише ${amount} ${currency}.`],
    [/^The fee of a same-currency transfer is the amount sent less the amount received: (\S+)\.$/, (fee) => `Комісія переказу в одній валюті — це різниця сум: ${fee}.`],
    [/^Money of a receipt of (\S+) was moved on from an account, to which less would arrive\.$/, () => 'Кошти цього надходження вже переказано далі, тож прибути на рахунок має не менше.'],
    [/^No tax rules are in effect on (\S+)\.$/, (date) => `Немає податкових правил на ${date}: їх додає оновлення застосунку.`],
    [/^The counter is at (\d+) already, and never goes back\.$/, (current) => `Лічильник уже на ${current} і назад не рухається.`],
    [/^The declaration can't change from (\w+) to (\w+)\.$/, () => 'Такої зміни статусу декларації немає. Оновіть сторінку.'],
    [/^\S+ is required: the updated_at of the invoice, which you edited\.$/, () => 'Оновіть сторінку і спробуйте знову.'],

    // Laravel's own validation messages, of any field
    [/^The .+ field is required\.$/, () => 'Заповніть це поле.'],
    [/^The .+ field must be at least (\d+) characters\.$/, (min) => `Щонайменше ${min} символів.`],
    [/^The .+ field must not be greater than (\d+) characters\.$/, (max) => `Не більше ${max} символів.`],
    [/^The .+ field must be (\d+) characters\.$/, (size) => `Має бути ${size} символи.`],
    [/^The .+ field confirmation does not match\.$/, () => 'Значення не збігаються.'],
    [/^The .+ has already been taken\.$/, () => 'Таке значення вже є.'],
    [/^The .+ field must be a valid email address\.$/, () => 'Перевірте адресу електронної пошти.'],
    [/^The selected .+ is invalid\.$/, () => 'Оберіть значення зі списку.'],
    [/^The .+ field must be a number\.$/, () => 'Введіть число.'],
    [/^The .+ field must be an integer\.$/, () => 'Введіть ціле число.'],
    [/^The .+ field must have (\S+) decimal places\.$/, (places) => `Знаків після коми: ${places.replace('-', '–')}.`],
    [/^The .+ field must be greater than or equal to (\S+)\.$/, (min) => `Має бути не менше ${min}.`],
    [/^The .+ field must be greater than (\S+)\.$/, (min) => `Має бути більше за ${min}.`],
    [/^The .+ field must be less than or equal to (\S+)\.$/, (max) => `Має бути не більше ${max}.`],
    [/^The .+ field must not be greater than (\d+) kilobytes\.$/, (kb) => `Файл завеликий: до ${Math.round(Number(kb) / 1024)} МБ.`],
    [/^The .+ field must not be greater than (\S+)\.$/, (max) => `Не більше ${max}.`],
    [/^The .+ field must be at least (\S+)\.$/, (min) => `Не менше ${min}.`],
    [/^The .+ field must be between (\S+) and (\S+)\.$/, (min, max) => `Від ${min} до ${max}.`],
    [/^The .+ field must be a valid date\.$/, () => 'Вкажіть дату.'],
    [/^The .+ field must be a date (after|after or equal to) .+\.$/, () => 'Дата раніша, ніж дозволено.'],
    [/^The .+ field must be a date (before|before or equal to) .+\.$/, () => 'Дата пізніша, ніж дозволено.'],
    [/^The .+ field must be a file of type: (.+)\.$/, (types) => `Потрібен файл ${types.toUpperCase()}.`],
    [/^The .+ field must contain at least one (.+)\.$/, (what) => `Пароль має містити хоча б ${PASSWORD_PARTS[what] ?? 'одну літеру й цифру'}.`],
];

/** What Laravel's password rules ask for, in the "must contain at least one …" message. */
const PASSWORD_PARTS: Record<string, string> = {
    letter: 'одну літеру',
    number: 'одну цифру',
    symbol: 'один символ',
    'uppercase and one lowercase letter': 'одну велику й одну малу літеру',
};

const BY_STATUS: Record<number, string> = {
    401: 'Сесія завершилась. Увійдіть знову.',
    403: 'Недостатньо прав для цієї дії.',
    404: 'Не знайдено.',
    409: 'Запис змінився або заблокований. Оновіть сторінку.',
    419: 'Сесія застаріла. Оновіть сторінку.',
    422: 'Перевірте дані форми.',
    429: 'Забагато спроб. Зачекайте хвилину і спробуйте знову.',
};

/**
 * A message for people: the translation of the API's message, or a text by the status.
 */
export function translateMessage(message: string, status = 0): string {
    if (MESSAGES[message]) {
        return MESSAGES[message];
    }

    for (const [pattern, translate] of PATTERNS) {
        const match = pattern.exec(message);

        if (match) {
            return translate(...match.slice(1));
        }
    }

    // A text without English words is Ukrainian already (the notifications' texts); an English one never reaches people
    return /[а-яіїєґ]/i.test(message) && !/\b[a-z]{2,}\b/.test(message)
        ? message
        : BY_STATUS[status] ?? 'Щось пішло не так. Спробуйте ще раз.';
}

/**
 * The message of any error thrown by a request.
 */
export function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return translateMessage(error.message, error.status);
    }

    return 'Не вдалося зв’язатися із сервером. Перевірте з’єднання і спробуйте ще раз.';
}

/**
 * The translated first message of a field of a 422.
 */
export function fieldError(error: unknown, field: string): string | undefined {
    if (!(error instanceof ApiError)) {
        return undefined;
    }

    const message = error.fieldError(field);

    return message === undefined ? undefined : translateMessage(message, 422);
}
