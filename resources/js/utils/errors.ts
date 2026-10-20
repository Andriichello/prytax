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
