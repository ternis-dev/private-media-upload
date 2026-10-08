// E2EE: AES-256-GCM per file, chunked container (see docs/e2ee-format.md).
// Key NEVER leaves the browser (URL fragment #k=...). Server sees ciphertext.

export const E2EE_MAGIC = 'PWF1';
export const E2EE_CHUNK_BYTES = 4 * 1024 * 1024;

function b64urlEncode(bytes: Uint8Array): string {
  let s = '';
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function b64urlDecode(s: string): Uint8Array {
  const b64 = s.replace(/-/g, '+').replace(/_/g, '/');
  const bin = atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4));
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function xorNonce(base: Uint8Array, index: number): Uint8Array {
  const n = base.slice();
  const view = new DataView(n.buffer, n.byteOffset, n.byteLength);
  view.setBigUint64(4, view.getBigUint64(4) ^ BigInt(index));
  return n;
}

async function importKey(raw: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', raw as BufferSource, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

export interface E2eeManifest {
  v: 1;
  filename: string;
  mime: string;
  size: number;
}

export interface EncryptedFile {
  container: Uint8Array;
  keyB64: string;
}

/** Encrypt bytes → PWF1 container + fresh key. Chunk 0 = encrypted manifest. */
export async function encryptFile(filename: string, mime: string, data: Uint8Array): Promise<EncryptedFile> {
  const keyRaw = crypto.getRandomValues(new Uint8Array(32));
  const nonceBase = crypto.getRandomValues(new Uint8Array(12));
  const key = await importKey(keyRaw);

  const manifest = new TextEncoder().encode(JSON.stringify({ v: 1, filename, mime, size: data.length }));
  const chunks: Uint8Array[] = [manifest];
  for (let off = 0; off < data.length; off += E2EE_CHUNK_BYTES) {
    chunks.push(data.subarray(off, off + E2EE_CHUNK_BYTES));
  }

  const parts: Uint8Array[] = [];
  const header = new Uint8Array(4 + 4 + 12 + 8);
  new TextEncoder().encodeInto(E2EE_MAGIC, header);
  const hv = new DataView(header.buffer);
  hv.setUint32(4, E2EE_CHUNK_BYTES);
  header.set(nonceBase, 8);
  const totalPlain = chunks.reduce((n, c) => n + c.length, 0);
  hv.setBigUint64(20, BigInt(totalPlain));
  parts.push(header);

  for (let i = 0; i < chunks.length; i++) {
    const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: xorNonce(nonceBase, i) as BufferSource }, key, chunks[i] as BufferSource));
    const len = new Uint8Array(4);
    new DataView(len.buffer).setUint32(0, ct.length);
    parts.push(len, ct);
  }

  const container = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let off = 0;
  for (const p of parts) {
    container.set(p, off);
    off += p.length;
  }
  return { container, keyB64: b64urlEncode(keyRaw) };
}

export interface DecryptedFile {
  filename: string;
  mime: string;
  data: Uint8Array;
}

/** Decrypt + verify a PWF1 container. Throws on tamper / wrong key / bad format. */
export async function decryptContainer(container: Uint8Array, keyB64: string): Promise<DecryptedFile> {
  const key = await importKey(b64urlDecode(keyB64));
  if (container.length < 28 || new TextDecoder().decode(container.subarray(0, 4)) !== E2EE_MAGIC) {
    throw new Error('not a PWF1 container');
  }
  const hv = new DataView(container.buffer, container.byteOffset, container.byteLength);
  const nonceBase = container.subarray(8, 20);
  const origSize = Number(hv.getBigUint64(20));

  const plains: Uint8Array[] = [];
  let off = 28;
  let index = 0;
  while (off < container.length) {
    if (off + 4 > container.length) throw new Error('truncated container');
    const len = new DataView(container.buffer, container.byteOffset + off, 4).getUint32(0);
    off += 4;
    if (off + len > container.length) throw new Error('truncated chunk');
    let plain: ArrayBuffer;
    try {
      plain = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: xorNonce(nonceBase, index) as BufferSource },
        key,
        container.subarray(off, off + len) as BufferSource,
      );
    } catch {
      throw new Error('decrypt failed (wrong key or tampered data)');
    }
    plains.push(new Uint8Array(plain));
    off += len;
    index++;
  }
  if (plains.length === 0) throw new Error('empty container');

  const manifest = JSON.parse(new TextDecoder().decode(plains[0])) as E2eeManifest;
  if (manifest.v !== 1 || typeof manifest.filename !== 'string' || typeof manifest.size !== 'number') {
    throw new Error('bad manifest');
  }
  const total = plains.reduce((n, p) => n + p.length, 0) - plains[0].length;
  if (total !== manifest.size || manifest.size + plains[0].length !== origSize) {
    throw new Error('size mismatch (tampered?)');
  }
  const data = new Uint8Array(manifest.size);
  let at = 0;
  for (let i = 1; i < plains.length; i++) {
    data.set(plains[i], at);
    at += plains[i].length;
  }
  return { filename: manifest.filename, mime: manifest.mime || 'application/octet-stream', data };
}

/** Key from URL fragment (#k=… or #…&k=…). Null when absent/invalid. */
export function parseShareKey(hash: string): string | null {
  const m = hash.match(/(?:^#|&)k=([A-Za-z0-9_-]{43})\b/);
  if (!m) return null;
  try {
    return b64urlDecode(m[1]).length === 32 ? m[1] : null;
  } catch {
    return null;
  }
}
