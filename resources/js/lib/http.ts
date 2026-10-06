/**
 * JSON requests to Torii's own endpoints outside Inertia visits. Sends the CSRF
 * token the way Inertia's own requests do: Laravel's XSRF-TOKEN cookie, echoed
 * back as the X-XSRF-TOKEN header.
 */

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        /** For a 422: the fields Laravel's validation flagged, e.g. ['previewId']. */
        public readonly fields: string[] = [],
    ) {
        super(message);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/** The message a user should see for a failed response. */
function messageFor(status: number, body: unknown): string {
    if (status === 422 && body && typeof body === 'object') {
        const { errors, message } = body as { errors?: Record<string, string[]>; message?: string };
        const first = errors ? Object.values(errors).flat()[0] : undefined;

        return first ?? message ?? 'The request was not valid.';
    }

    if (status === 419) {
        return 'Your session expired. Reload the page and try again.';
    }

    if (status === 429) {
        return 'Too many requests in a short time. Wait a minute and try again.';
    }

    return `Something went wrong on the server (HTTP ${status}).`;
}

export async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
    const token = xsrfToken();
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify(body),
            signal,
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new HttpError(0, "Couldn't reach Torii. Check your connection and try again.");
    }

    if (!response.ok) {
        const data: unknown = await response.json().catch(() => null);
        const errors = data && typeof data === 'object' ? (data as { errors?: Record<string, unknown> }).errors : undefined;

        throw new HttpError(response.status, messageFor(response.status, data), errors ? Object.keys(errors) : []);
    }

    return (await response.json()) as T;
}
