import { ApiError } from '@/api/client';
import { type Locale, locale, t } from '@/i18n';

/**
 * The texts of the messages, which the API sends in English whatever the language asked for: api-core's own (see its
 * docs/localization/01-translations.md) and Laravel's throttling. Everything else comes in the language of the
 * screens: the API answers in the signed-in user's `locale`, and before signing in in the client's `Accept-Language`.
 */
const API_MESSAGES: Record<string, () => string> = {
    'This account is suspended.': () => t('core.apiMessages.accountSuspended'),
    'Too Many Attempts.': () => t('core.errors.tooManyAttempts'),
    'Password update is required to use your account.': () => t('core.apiMessages.passwordUpdateRequired'),
    'The invitation link is invalid, has expired or has been used.': () => t('core.apiMessages.invitationInvalid'),
    'The email is used in several organizations, so choose one of them.': () => t('core.apiMessages.severalOrganizations'),
};

/**
 * The letters of a language of the screens, which English doesn't write with: a message without them isn't in that
 * language. A language written in Latin letters has none.
 */
const LETTERS: Partial<Record<Locale, RegExp>> = {
    uk: /[а-яіїєґ]/i, // i18n-ignore
};

/**
 * A text by the status of an answer, for when its message isn't one for people.
 */
function byStatus(status: number): string {
    switch (status) {
        case 401:
            return t('core.errors.sessionEnded');
        case 403:
            return t('core.errors.forbidden');
        case 404:
            return t('core.errors.notFound');
        case 409:
            return t('core.errors.conflict');
        case 419:
            return t('core.errors.sessionExpired');
        case 422:
            return t('core.errors.checkForm');
        case 429:
            return t('core.errors.tooManyAttempts');
        default:
            return t('core.errors.somethingWentWrong');
    }
}

/**
 * Whether a message of the API is one for people in the language of the screens. In a language with letters of its
 * own, they show that the API translated it. In one written in Latin letters, an English message can't be told from
 * a translated one, so it is shown unless it has another language's letters or isn't for people (a server error, an
 * ended session).
 */
function isForPeople(message: string, status: number): boolean {
    const letters = LETTERS[locale.value];

    if (letters) {
        return letters.test(message);
    }

    return message.trim() !== ''
        && !Object.values(LETTERS).some((other) => other.test(message))
        && status !== 401 && status !== 419 && status < 500;
}

/**
 * A message for people: the API's message in the language of the screens, the translation of one of its English-only
 * ones, or else a text by the status.
 */
export function translateMessage(message: string, status = 0): string {
    const translate = API_MESSAGES[message];

    if (translate) {
        return translate();
    }

    return isForPeople(message, status) ? message : byStatus(status);
}

/**
 * The message of any error thrown by a request.
 */
export function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
        return translateMessage(error.message, error.status);
    }

    return t('core.errors.offline');
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
