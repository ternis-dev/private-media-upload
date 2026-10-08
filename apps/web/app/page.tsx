'use client';
import { useState } from 'react';
import { TIERS, type TierId } from '../lib/tiers';
import { CHUNK_BYTES, appendChunk, completeUpload, initUpload } from '../lib/uploads';

const API = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8000';

export default function Home() {
  const [tier, setTier] = useState<TierId>('L2');
  const [file, setFile] = useState<File | null>(null);
  const [progress, setProgress] = useState('');
  const [share, setShare] = useState('');

  async function upload() {
    if (!file) {
      setProgress('pick a file first');
      return;
    }
    try {
      setProgress('reserving…');
      const init = await initUpload(API, { tier, filename: file.name, size: file.size, mime: file.type || 'application/octet-stream' });
      let offset = 0;
      while (offset < file.size) {
        const chunk = file.slice(offset, offset + CHUNK_BYTES);
        const p = await appendChunk(API, init.uploadId, chunk);
        offset = p.received;
        setProgress(`uploading… ${((offset / file.size) * 100).toFixed(0)}% (${init.tier})`);
      }
      setProgress('finalizing…');
      const done = await completeUpload(API, init.uploadId);
      setShare(done.shareUrl);
      setProgress('done ✓');
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
