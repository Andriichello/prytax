import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, request } from '@/api/client';
import { locale } from '@/i18n';
import { errorMessage, fieldError, translateMessage } from '@/utils/errors';

describe('translateMessage', () => {
    it('shows the API\'s Ukrainian messages as they are, Latin names and codes too', () => {
        expect(translateMessage('Надходження покривають 3100.00 з 3892.50 USD, тож інвойс ще не оплачено.', 422))
            .toBe('Надходження покривають 3100.00 з 3892.50 USD, тож інвойс ще не оплачено.');
        expect(translateMessage('На рахунку Wise USD від цього надходження лишилося лише 10.00 USD.', 422))
            .toBe('На рахунку Wise USD від цього надходження лишилося лише 10.00 USD.');
    });

    it('translates api-core\'s English-only messages', () => {
        expect(translateMessage('This account is suspended.', 403)).toBe('Обліковий запис призупинено. Зверніться до власника організації.');
    });

    it('never shows other English messages, but a text by the status', () => {
        expect(translateMessage('Not found.', 404)).toBe('Не знайдено.');
        expect(translateMessage('Server Error', 500)).toBe('Щось пішло не так. Спробуйте ще раз.');
    });

    describe('on English screens', () => {
        afterEach(() => {
            locale.value = 'uk';
        });

        it('shows the API\'s English messages, but not the Ukrainian ones or those not for people', () => {
            locale.value = 'en';

            expect(translateMessage('The amount must be greater than 0.', 422)).toBe('The amount must be greater than 0.');
            expect(translateMessage('Надходження покривають 3100.00 з 3892.50 USD.', 422)).toBe('Check the form.');
            expect(translateMessage('Server Error', 500)).toBe('Something went wrong. Try again.');
            expect(translateMessage('This account is suspended.', 403)).toBe('This account is suspended. Contact the organization’s owner.');
        });

        it('shows the API\'s English messages with Ukrainian terms and names, by the language the answer names', () => {
            locale.value = 'en';

            expect(translateMessage('РНОКПП is 10 digits.', 422, 'en')).toBe('РНОКПП is 10 digits.');
            expect(fieldError(new ApiError(422, 'Check', { tax_id: ['Another ФОП of the organization has this tax id.'] }, 'en'), 'tax_id'))
                .toBe('Another ФОП of the organization has this tax id.');
            expect(translateMessage('Надходження покривають 3100.00 з 3892.50 USD.', 422, 'uk')).toBe('Check the form.');
        });
    });

    it('never shows an answer in another language, though it has Ukrainian terms', () => {
        expect(translateMessage('Another ФОП of the organization has this tax id.', 422, 'en')).toBe('Перевірте дані форми.');
    });
});

describe('errors of requests', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('takes a field\'s first message', () => {
        const error = new ApiError(422, 'Перевірте дані форми.', { name: ['Заповніть це поле.', 'Не більше 255 символів.'] });

        expect(fieldError(error, 'name')).toBe('Заповніть це поле.');
        expect(fieldError(error, 'currency')).toBeUndefined();
    });

    it('keeps the language the answer names', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(
            JSON.stringify({ message: 'РНОКПП is 10 digits.', errors: { tax_id: ['РНОКПП is 10 digits.'] } }),
            { status: 422, headers: { 'Content-Language': 'en', 'Content-Type': 'application/json' } },
        ));

        const caught = await request('profiles/1').catch((error: unknown) => error);

        expect(caught).toBeInstanceOf(ApiError);
        expect((caught as ApiError).language).toBe('en');
    });

    it('tells a failed connection apart from an answer', () => {
        expect(errorMessage(new TypeError('Failed to fetch'))).toMatch(/з’єднання/);
    });
});
