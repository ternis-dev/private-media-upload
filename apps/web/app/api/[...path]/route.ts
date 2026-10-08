import { NextRequest, NextResponse } from 'next/server';

// Same-origin API proxy (M4 fix): the browser talks to /api/*, the server
// forwards to the Laravel API. This removes three whole failure classes:
// wrong-host NEXT_PUBLIC URLs baked at build time, CORS, and mixed content.
// Runtime server-side env (docker-compose friendly), never baked in.
const BACKEND = process.env.API_BASE ?? 'http://localhost:8000';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

const FORWARD_REQ = new Set([
  'content-type',
  'authorization',
  'x-share-password',
  'upload-length',
  'upload-metadata',
  'upload-offset',
  'tus-resumable',
  'range',
  'accept',
]);

const FORWARD_RES = new Set([
  'content-type',
  'content-disposition',
  'content-range',
  'accept-ranges',
  'content-length',
  'location',
  'upload-offset',
  'upload-length',
  'tus-resumable',
  'tus-version',
  'tus-extension',
  'tus-max-size',
  'x-ratelimit-remaining',
  'retry-after',
  'cache-control',
]);

async function proxy(req: NextRequest, method: string): Promise<NextResponse> {
  const path = req.nextUrl.pathname.replace(/^\/api/, '') || '/';
  const url = `${BACKEND.replace(/\/$/, '')}${path}${req.nextUrl.search}`;

  const headers: Record<string, string> = {};
  req.headers.forEach((v, k) => {
    if (FORWARD_REQ.has(k.toLowerCase())) headers[k] = v;
  });

  let body: BodyInit | undefined;
  if (method !== 'GET' && method !== 'HEAD') {
    const buf = await req.arrayBuffer();
    if (buf.byteLength > 0) body = Buffer.from(buf);
  }

  let upstream: Response;
  try {
    upstream = await fetch(url, { method, headers, body, redirect: 'follow' });
  } catch {
    return NextResponse.json(
      { error: `API unreachable at ${BACKEND} — is the backend running and API_BASE correct?` },
      { status: 502 },
    );
  }

  const outHeaders: Record<string, string> = {};
  upstream.headers.forEach((v, k) => {
    if (FORWARD_RES.has(k.toLowerCase())) outHeaders[k] = v;
  });
  // Redirects are followed server-side; the client always gets final bytes.
  return new NextResponse(upstream.body, { status: upstream.status, headers: outHeaders });
}

export const GET = (req: NextRequest) => proxy(req, 'GET');
export const POST = (req: NextRequest) => proxy(req, 'POST');
export const PUT = (req: NextRequest) => proxy(req, 'PUT');
export const PATCH = (req: NextRequest) => proxy(req, 'PATCH');
export const DELETE = (req: NextRequest) => proxy(req, 'DELETE');
export const HEAD = (req: NextRequest) => proxy(req, 'HEAD');
export const OPTIONS = (req: NextRequest) => proxy(req, 'OPTIONS');
