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
    return MESSAGES[message] ?? (/[а-яіїєґ]/i.test(message) ? message : BY_STATUS[status] ?? 'Щось пішло не так. Спробуйте ще раз.');
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
