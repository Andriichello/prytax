import { ApiError } from '@/api/client';

/**
 * Ukrainian texts of the messages, which the API sends in English whatever the language asked for: api-core's own
 * (see its docs/localization/01-translations.md) and Laravel's throttling. Everything else comes in Ukrainian, since
 * the client asks for it (`Accept-Language: uk`).
 */
const MESSAGES: Record<string, string> = {
    'This account is suspended.': 'Обліковий запис призупинено. Зверніться до власника організації.',
    'Too Many Attempts.': 'Забагато спроб. Зачекайте хвилину і спробуйте знову.',
    'Password update is required to use your account.': 'Змініть пароль, щоб продовжити роботу.',
    'The invitation link is invalid, has expired or has been used.': 'Посилання із запрошення недійсне, застаріле або вже використане. Попросіть запросити вас знову.',
    'The email is used in several organizations, so choose one of them.': 'Ця пошта є в кількох організаціях. Зверніться до власника організації.',
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
 * A message for people: the API's Ukrainian message, the translation of one of its English-only ones, or else a
 * text by the status (an English message never reaches people).
 */
export function translateMessage(message: string, status = 0): string {
    if (MESSAGES[message]) {
        return MESSAGES[message];
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
