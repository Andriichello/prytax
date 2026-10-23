import { ApiError } from '@/api/client';
import { locale, t } from '@/i18n';

/**
 * Ukrainian texts of the messages, which the API sends in English whatever the language asked for: api-core's own
 * (see its docs/localization/01-translations.md) and Laravel's throttling. Everything else comes in the language of the
 * screens: the API answers in the signed-in user's `locale`, and before signing in in the client's `Accept-Language`.
 */
// i18n-ignore-start: the Ukrainian of English-only messages, shown in Ukrainian only
const MESSAGES: Record<string, string> = {
    'This account is suspended.': 'Обліковий запис призупинено. Зверніться до власника організації.',
    'Too Many Attempts.': 'Забагато спроб. Зачекайте хвилину і спробуйте знову.',
    'Password update is required to use your account.': 'Змініть пароль, щоб продовжити роботу.',
    'The invitation link is invalid, has expired or has been used.': 'Посилання із запрошення недійсне, застаріле або вже використане. Попросіть запросити вас знову.',
    'The email is used in several organizations, so choose one of them.': 'Ця пошта є в кількох організаціях. Зверніться до власника організації.',
};
// i18n-ignore-end

/**
 * A text by the status of an answer, for when its message isn't one for people.
 */
function byStatus(status: number): string {
    switch (status) {
        case 401:
            return t('Сесія завершилась. Увійдіть знову.');
        case 403:
            return t('Недостатньо прав для цієї дії.');
        case 404:
            return t('Не знайдено.');
        case 409:
            return t('Запис змінився або заблокований. Оновіть сторінку.');
        case 419:
            return t('Сесія застаріла. Оновіть сторінку.');
        case 422:
            return t('Перевірте дані форми.');
        case 429:
            return t('Забагато спроб. Зачекайте хвилину і спробуйте знову.');
        default:
            return t('Щось пішло не так. Спробуйте ще раз.');
    }
}

/**
 * A message for people. In Ukrainian: the API's Ukrainian message, the translation of one of its English-only ones,
 * or else a text by the status (an English message never reaches people). In English: the API's message, unless it
 * isn't one for people (a server error, an ended session) or isn't English, when it's a text by the status.
 */
export function translateMessage(message: string, status = 0): string {
    const isUkrainian = /[а-яіїєґ]/i.test(message); // i18n-ignore

    if (locale.value === 'en') {
        return message.trim() && !isUkrainian && status !== 401 && status !== 419 && status < 500 ? message : byStatus(status);
    }

    if (MESSAGES[message]) {
        return MESSAGES[message];
    }

    return isUkrainian ? message : byStatus(status);
}

/**
 * The message of any error thrown by a request.
 */
export function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return translateMessage(error.message, error.status);
    }

    return t('Не вдалося зв’язатися із сервером. Перевірте з’єднання і спробуйте ще раз.');
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
