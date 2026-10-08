// M1 client for the chunked upload flow:
// init → PUT chunks (4 MB) → complete → shareUrl. No dependencies.
import type { TierId } from './tiers';

export const CHUNK_BYTES = 4 * 1024 * 1024;

export interface InitInput {
  tier: TierId;
  filename: string;
  size: number;
  mime: string;
}

export interface InitResponse {
  uploadId: string;
  key: string;
  tier: TierId;
  expected: number;
  appendUrl: string;
  mode: string;
  presignedPutUrl?: string;
}

export interface Progress {
  received: number;
  expected: number;
  done: boolean;
}

export function chunkCount(size: number, chunkBytes: number = CHUNK_BYTES): number {
  if (size <= 0) return 0;
  return Math.ceil(size / chunkBytes);
}

/** Accept a bare id or any /s/:id URL; null when invalid. */
export function parseShareId(urlOrId: string): string | null {
  const m = urlOrId.trim().match(/(?:\/s\/)?([0-9A-Za-z]{8,32})\/?(?:[#?].*)?$/);
  return m ? m[1] : null;
}

async function mustJson(res: Response): Promise<never> {
  const text = await res.text();
  throw new Error(`API ${res.status}: ${text.slice(0, 200)}`);
}

export async function initUpload(apiBase: string, input: InitInput): Promise<InitResponse> {
  const res = await fetch(`${apiBase}/v1/uploads/init`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input),
  });
  if (!res.ok) await mustJson(res);
  return res.json();
}

export async function appendChunk(apiBase: string, uploadId: string, chunk: Blob): Promise<Progress> {
  const res = await fetch(`${apiBase}/v1/uploads/${uploadId}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/octet-stream' },
    body: chunk,
  });
  if (!res.ok) await mustJson(res);
  return res.json();
}

export interface CompleteOptions {
  password?: string;
  maxViews?: number;
  burn?: boolean;
  e2ee?: boolean;
}

export async function completeUpload(
  apiBase: string,
  uploadId: string,
  opts: CompleteOptions = {},
): Promise<{ shareId: string; shareUrl: string }> {
  const body: Record<string, unknown> = {};
  if (opts.password) body.password = opts.password;
  if (opts.maxViews) body.maxViews = opts.maxViews;
  if (opts.burn) body.burn = true;
  if (opts.e2ee) body.e2ee = true;
  const res = await fetch(`${apiBase}/v1/uploads/${uploadId}/complete`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) await mustJson(res);
  return res.json();
}
