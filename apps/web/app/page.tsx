'use client';
import { useState } from 'react';
import { TIERS, type TierId } from '../lib/tiers';
import { CHUNK_BYTES, appendChunk, completeUpload, initUpload } from '../lib/uploads';
import { encryptFile } from '../lib/e2ee';

const API = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8000';

export default function Home() {
  const [tier, setTier] = useState<TierId>('L2');
  const [file, setFile] = useState<File | null>(null);
  const [password, setPassword] = useState('');
  const [burn, setBurn] = useState(false);
  const [e2ee, setE2ee] = useState(false);
  const [progress, setProgress] = useState('');
  const [share, setShare] = useState('');

  async function upload() {
    if (!file) {
      setProgress('pick a file first');
      return;
    }
    try {
      let name = file.name;
      let mime = file.type || 'application/octet-stream';
      let bytes = new Uint8Array(await file.arrayBuffer());
      let keyFrag = '';
      if (e2ee) {
        if (file.size > 1024 * 1024 * 1024) {
          setProgress('E2EE limited to 1 GB in this version (streaming decrypt lands later)');
          return;
        }
        setProgress('encrypting in browser…');
        const enc = await encryptFile(file.name, mime, bytes);
        bytes = enc.container;
        keyFrag = `#k=${enc.keyB64}`;
        name = `${file.name}.pwf1`;
        mime = 'application/octet-stream';
      }
      setProgress('reserving…');
      const init = await initUpload(API, { tier, filename: name, size: bytes.length, mime });
      let offset = 0;
      while (offset < bytes.length) {
        const p = await appendChunk(API, init.uploadId, new Blob([bytes.subarray(offset, offset + CHUNK_BYTES)]));
        offset = p.received;
        setProgress(`uploading… ${((offset / bytes.length) * 100).toFixed(0)}% (${init.tier}${e2ee ? ', E2EE 🔒' : ''})`);
      }
      setProgress('finalizing…');
      const done = await completeUpload(API, init.uploadId, {
        password: password || undefined,
        burn: burn || undefined,
        e2ee: e2ee || undefined,
      });
      setShare(done.shareUrl + keyFrag);
      setProgress(e2ee ? 'done ✓ — server never saw plaintext (key only in link fragment)' : 'done ✓');
    } catch (e) {
      setProgress(`failed: ${e instanceof Error ? e.message : String(e)}`);
    }
  }

  return (
    <>
      <h1>Upload — pick your privacy tier</h1>
      {TIERS.map((t) => (
        <label key={t.id} style={{ display: 'block', border: '1px solid #ccc', borderRadius: 8, padding: 12, margin: '8px 0' }}>
          <input type="radio" name="tier" checked={tier === t.id} onChange={() => setTier(t.id)} />{' '}
          <strong>{t.id}</strong> — {t.name} <em>{t.badge}</em>
          <div style={{ opacity: 0.7 }}>{t.hint}</div>
        </label>
      ))}
      <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      <div style={{ marginTop: 8 }}>
        <label>
          Password (optional, min 8 chars):{' '}
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
      </div>
      <div>
        <label>
          <input type="checkbox" checked={burn} onChange={(e) => setBurn(e.target.checked)} /> burn after first read
        </label>
      </div>
      <div>
        <label>
          <input type="checkbox" checked={e2ee} onChange={(e) => setE2ee(e.target.checked)} /> 🔒 end-to-end encrypt (key stays in link fragment, server sees ciphertext)
        </label>
      </div>
      <div style={{ marginTop: 12 }}>
        <button onClick={upload} style={{ padding: '10px 18px', fontSize: 16 }}>
          Upload{file ? ` ${file.name} (${(file.size / 1048576).toFixed(1)} MB)` : ''} via {tier}
        </button>
      </div>
      <pre>{progress}</pre>
      {share && (
        <p>
          Share link: <a href={share}>{share}</a>
        </p>
      )}
    </>
  );
}
