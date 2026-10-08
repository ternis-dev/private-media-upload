// Same-origin API calls (see app/api/[...path]/route.ts proxy).
// Throws friendly Errors so UI shows actionable messages, never raw overlays.

export async function apiFetch(path: string, init: RequestInit = {}): Promise<Response> {
  let res: Response;
  try {
    res = await fetch(`/api${path}`, init);
  } catch {
    throw new Error('Cannot reach the server (network error). Is the app fully started? Try reloading once.');
  }
  return res;
}

export async function apiJson<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await apiFetch(path, init);
  let body: { error?: string } & Record<string, unknown> = {};
  try {
    body = await res.json();
  } catch {
    throw new Error(`Server returned ${res.status} with an unreadable body.`);
  }
  if (!res.ok) {
    if (res.status === 502 && typeof body.error === 'string') throw new Error(body.error);
    throw new Error(typeof body.error === 'string' ? body.error : `Request failed (${res.status}).`);
  }
  return body as T;
}

export function apiUrl(path: string): string {
  return `/api${path}`;
}
