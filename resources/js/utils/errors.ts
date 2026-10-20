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
];

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

    return /[а-яіїєґ]/i.test(message) ? message : BY_STATUS[status] ?? 'Щось пішло не так. Спробуйте ще раз.';
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
