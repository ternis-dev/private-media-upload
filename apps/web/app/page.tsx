'use client';
import { useState } from 'react';
import { TIERS, type TierId } from '../lib/tiers';

export default function Home() {
  const [tier, setTier] = useState<TierId>('L2');
  const [result, setResult] = useState<string>('');

  async function initUpload() {
    setResult('connecting to API…');
    try {
      const r = await fetch('http://localhost:8000/v1/uploads/init', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tier, filename: 'demo.mp4', size: 1024, mime: 'video/mp4' }),
      });
      setResult(`${r.status} ${await r.text()}`);
    } catch (e) {
      setResult(`API unreachable (${String(e)}). Run: composer serve in apps/api.`);
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
      <button onClick={initUpload} style={{ padding: '10px 18px', fontSize: 16 }}>
        Init upload ({tier})
      </button>
      <pre>{result}</pre>
      <p style={{ opacity: 0.6 }}>M0: init-only. Bytes flow in M1 (L1 presigned R2 / L2-L3 tus).</p>
    </>
  );
}
