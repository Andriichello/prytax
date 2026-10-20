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
 * An error answer of the API: its status, message and, for a 422, the messages of each field.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
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
        'Accept-Language': 'uk',
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
 * Send a request and return its JSON body; an error answer throws an ApiError. A stale CSRF token (419) is renewed
 * and the request sent once more.
 */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    let response = await send(path, options);

    if (response.status === 419) {
        await fetchCsrfCookie();
        response = await send(path, options);
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

        throw new ApiError(response.status, message ?? response.statusText, errors ?? {});
    }

    return body as T;
}

export const api = {
    get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>(path, { query, signal }),
    post: <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
        request<T>(path, { method: 'POST', body, headers }),
    patch: <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
        request<T>(path, { method: 'PATCH', body, headers }),
    delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};
