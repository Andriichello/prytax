import { ref } from 'vue';
import { locale } from '@/i18n';

/**
 * The API client: `fetch` with the session cookie, Sanctum's CSRF header and api-core's JSON envelopes.
 *
 * Paths are relative to `/api/` (`profiles/1/invoices`). Queries are objects, nested into brackets the way api-core
 * reads them: `{ filter: { status: { in: ['ready', 'sent'] } }, page: { number: 2 } }` becomes
 * `filter[status][in]=ready,sent&page[number]=2`.
 */

export type QueryValue = string | number | boolean | null | undefined | QueryValue[] | { [key: string]: QueryValue };
export type Query = Record<string, QueryValue>;

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
    query?: Query;
    body?: unknown;
    headers?: Record<string, string>;
    signal?: AbortSignal;
}

/**
 * An error answer of the API: its status, message and, for a 422, the messages of each field, with the language they
 * are in (`Content-Language`), when the answer names one.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
        public readonly language: string | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** The first message of a field, for showing under its input. */
    fieldError(field: string): string | undefined {
        return this.errors[field]?.[0];
    }
}

const API_PREFIX = '/api/';

let unauthenticatedHandler: (() => void) | null = null;

/**
 * Set what happens when the API answers 401: the session ended, so the app signs the user out locally.
 */
export function onUnauthenticated(handler: () => void): void {
    unauthenticatedHandler = handler;
}

/**
 * Serialize a query object into api-core's bracketed parameters; lists are joined by commas, empty values left out.
 */
export function toQueryString(query: Query = {}): string {
    const parts: string[] = [];

    const add = (key: string, value: QueryValue): void => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        if (Array.isArray(value)) {
            if (value.length > 0) {
                parts.push(`${encodeURIComponent(key)}=${value.map((item) => encodeURIComponent(String(item))).join(',')}`);
            }

            return;
        }

        if (typeof value === 'object') {
            for (const [child, childValue] of Object.entries(value)) {
                add(`${key}[${child}]`, childValue);
            }

            return;
        }

        parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(typeof value === 'boolean' ? (value ? '1' : '0') : String(value))}`);
    };

    for (const [key, value] of Object.entries(query)) {
        add(key, value);
    }

    return parts.join('&');
}

/**
 * The URL of an API path, e.g. for a download link.
 */
export function apiUrl(path: string, query?: Query): string {
    const queryString = toQueryString(query);

    return API_PREFIX + path.replace(/^\/+/, '') + (queryString ? `?${queryString}` : '');
}

function readCookie(name: string): string | null {
    const match = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

/**
 * Ask Sanctum for the CSRF cookie (before signing in, and after a 419).
 */
export async function fetchCsrfCookie(): Promise<void> {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
}

async function send(path: string, options: RequestOptions): Promise<Response> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Accept-Language': locale.value,
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    let body: BodyInit | undefined;

    if (options.body instanceof FormData) {
        body = options.body;
    } else if (options.body !== undefined) {
        body = JSON.stringify(options.body);
        headers['Content-Type'] = 'application/json';
    }

    if (method !== 'GET') {
        const token = readCookie('XSRF-TOKEN');

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    return fetch(apiUrl(path, options.query), {
        method,
        headers,
        body,
        credentials: 'same-origin',
        signal: options.signal,
    });
}

/**
 * How many writes the API accepted in this session: every request but a GET, except the ones that save nothing (a
 * receipt's preview, a transfer's proposal). A screen kept alive (KeepAlive) reads its data again on return only when
 * this moved, so going back shows it as it was left, scroll and all, unless something may have changed it.
 */
export const dataVersion = ref(0);

/** The requests, which only compute an answer and write nothing, though they are POSTs. */
const SAVES_NOTHING = /\/(preview|propose|probe)$/;

/**
 * Send a request and return its JSON body; an error answer throws an ApiError. A stale CSRF token (419) is renewed
 * and the request sent once more. An accepted write moves `dataVersion`.
 */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    let response = await send(path, options);

    if (response.status === 419) {
        await fetchCsrfCookie();
        response = await send(path, options);
    }

    if (response.ok && (options.method ?? 'GET') !== 'GET' && !SAVES_NOTHING.test(path)) {
        dataVersion.value++;
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        const { message, errors } = (body ?? {}) as { message?: string; errors?: Record<string, string[]> };

        if (response.status === 401 && unauthenticatedHandler) {
            unauthenticatedHandler();
        }

        throw new ApiError(response.status, message ?? response.statusText, errors ?? {}, response.headers.get('Content-Language'));
    }

    return body as T;
}

/**
 * Send a GET request for a file (a PDF) and return its body as a blob; an error answer throws an ApiError, as
 * `request()` does.
 */
async function blob(path: string, query?: Query, signal?: AbortSignal): Promise<Blob> {
    const response = await send(path, { query, signal });

    if (!response.ok) {
        const { message } = ((await response.json().catch(() => null)) ?? {}) as { message?: string };

        if (response.status === 401 && unauthenticatedHandler) {
            unauthenticatedHandler();
        }

        throw new ApiError(response.status, message ?? response.statusText, {}, response.headers.get('Content-Language'));
    }

    return response.blob();
}

/**
 * Every row of a list, page after page (the API serves 50 at most per page): for selects and short lists, which
 * must not stop silently at the first page.
 */
async function all<T>(path: string, query: Query = {}, signal?: AbortSignal): Promise<T[]> {
    const rows: T[] = [];

    for (let page = 1; ; page++) {
        const result = await request<{ data: T[]; meta: { links: { next: string | null } } }>(path, {
            query: { ...query, page: { ...(query.page as Record<string, QueryValue> | undefined), size: 50, number: page } },
            signal,
        });

        rows.push(...result.data);

        if (result.meta.links.next === null || result.data.length === 0) {
            return rows;
        }
    }
}

export const api = {
    get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>(path, { query, signal }),
    blob,
    all,
    post: <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
        request<T>(path, { method: 'POST', body, headers }),
    patch: <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
        request<T>(path, { method: 'PATCH', body, headers }),
    put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
    /** A delete; the query carries what the API reads besides the path (a declared quarter's `correction_note`). */
    delete: <T>(path: string, query?: Query) => request<T>(path, { method: 'DELETE', query }),
};

/**
 * A key for the `Idempotency-Key` header of a form's POST (receipts, refunds, transfers, tax payments): made once per
 * form, it makes a repeated tap or a retry after a lost answer return the first result instead of recording twice.
 */
export function newIdempotencyKey(): string {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}
