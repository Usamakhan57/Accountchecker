import type { ApiEnvelope, ApiFailure } from '@/types/api';

/**
 * Thin fetch wrapper around the PHP API.
 *
 * Every call sends credentials so the HttpOnly session cookie travels with it,
 * and every non-success envelope is turned into an ApiError the UI can render
 * without knowing anything about HTTP.
 */

const API_BASE = (import.meta.env.VITE_API_URL ?? '').replace(/\/+$/, '');

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fieldErrors: Record<string, string[]>;

  constructor(message: string, status: number, code: string, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fieldErrors = fieldErrors;
  }

  /** True when the user simply is not signed in (or the session expired). */
  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  /** First message for a field, for inline form errors. */
  fieldError(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Record<string, string | number | boolean | undefined | null>;
  signal?: AbortSignal;
  /** Set for multipart uploads, where the browser must pick the boundary. */
  formData?: FormData;
}

type UnauthenticatedHandler = () => void;

let onUnauthenticated: UnauthenticatedHandler | null = null;

/**
 * Registered once by AuthProvider so an expired session clears app state
 * wherever the 401 surfaces, instead of each caller handling it.
 */
export function setUnauthenticatedHandler(handler: UnauthenticatedHandler | null): void {
  onUnauthenticated = handler;
}

/** Methods the API treats as safe, and which therefore need no CSRF token. */
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

const CSRF_COOKIE = 'accountcheck_csrf';

/**
 * Reads the CSRF token the API issued.
 *
 * This cookie is deliberately not HttpOnly: echoing it back in a header is
 * what proves the request came from our own code, since another origin can
 * cause the cookie to be sent but cannot read it. The session cookie stays
 * HttpOnly and is never touched here.
 */
function csrfToken(): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${CSRF_COOKIE}=([^;]*)`));

  return match ? decodeURIComponent(match[1]) : null;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;

  if (!query) {
    return url;
  }

  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value));
    }
  }

  const queryString = params.toString();
  return queryString ? `${url}?${queryString}` : url;
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return send<T>(path, options, true);
}

async function send<T>(path: string, options: RequestOptions, mayRetry: boolean): Promise<T> {
  const { method = 'GET', body, query, signal, formData } = options;

  const headers: Record<string, string> = { Accept: 'application/json' };
  let payload: BodyInit | undefined;

  if (formData) {
    payload = formData;
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  // Anything that can change state carries the token. The API issues it on any
  // read, and the app performs one at start-up, so it is always present by the
  // time a write happens.
  if (!SAFE_METHODS.has(method)) {
    const token = csrfToken();

    if (token) {
      headers['X-CSRF-Token'] = token;
    }
  }

  let response: Response;

  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers,
      body: payload,
      // The session cookie is HttpOnly, so it must be sent explicitly on
      // cross-origin calls from the Vite dev server and the built app alike.
      credentials: 'include',
      signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw error;
    }
    throw new ApiError(
      'Could not reach the server. Check your connection and try again.',
      0,
      'NETWORK_ERROR',
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  let envelope: ApiEnvelope<T> | null = null;

  try {
    envelope = (await response.json()) as ApiEnvelope<T>;
  } catch {
    envelope = null;
  }

  if (!envelope) {
    throw new ApiError(
      'The server returned an unreadable response.',
      response.status,
      'MALFORMED_RESPONSE',
    );
  }

  if (!envelope.success) {
    const failure = envelope as ApiFailure;

    if (response.status === 401 && onUnauthenticated) {
      onUnauthenticated();
    }

    // A write can land before the app's first read has returned a token, which
    // is a race rather than a real forgery. One cheap read fetches the token,
    // and the write is tried once more; a second failure is reported as it is.
    if (response.status === 419 && mayRetry && !SAFE_METHODS.has(method)) {
      await fetch(buildUrl('/api/health'), { credentials: 'include' }).catch(() => undefined);

      if (csrfToken()) {
        return send<T>(path, options, false);
      }
    }

    throw new ApiError(
      failure.message || 'The request could not be completed.',
      response.status,
      failure.error_code || 'REQUEST_FAILED',
      failure.errors ?? {},
    );
  }

  return envelope.data;
}

/**
 * Downloads a file endpoint (CSV/TXT export) and hands back a Blob plus the
 * filename the server suggested.
 */
export async function download(
  path: string,
  query?: RequestOptions['query'],
): Promise<{ blob: Blob; filename: string }> {
  const response = await fetch(buildUrl(path, query), {
    method: 'GET',
    credentials: 'include',
    headers: { Accept: 'text/csv, text/plain, application/json' },
  });

  if (!response.ok) {
    let message = 'The export could not be generated.';
    let code = 'EXPORT_FAILED';

    try {
      const failure = (await response.json()) as ApiFailure;
      message = failure.message || message;
      code = failure.error_code || code;
    } catch {
      /* Keep the generic message when the body is not JSON. */
    }

    if (response.status === 401 && onUnauthenticated) {
      onUnauthenticated();
    }

    throw new ApiError(message, response.status, code);
  }

  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^"]+)"?/i.exec(disposition);

  return {
    blob: await response.blob(),
    filename: match?.[1] ?? 'accountcheck-export',
  };
}

export const api = {
  get: <T>(path: string, query?: RequestOptions['query'], signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', query, signal }),
  post: <T>(path: string, body?: unknown, signal?: AbortSignal) =>
    request<T>(path, { method: 'POST', body, signal }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  upload: <T>(path: string, formData: FormData) => request<T>(path, { method: 'POST', formData }),
  download,
};
