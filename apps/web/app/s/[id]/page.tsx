'use client';
import { use, useCallback, useEffect, useState } from 'react';

const API = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8000';

interface Meta {
  id: string;
  tier: string;
  badge: string;
  residency: string;
  filename: string;
  mime: string;
  size: number;
  expiresAt: string;
  hasPassword: boolean;
  views: number;
  maxViews: number | null;
  burn: boolean;
}

export default function SharePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const [password, setPassword] = useState('');
  const [needPassword, setNeedPassword] = useState(false);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [error, setError] = useState('');
  const [downloading, setDownloading] = useState(false);

  const load = useCallback(
    async (pw?: string) => {
      setError('');
      const res = await fetch(`${API}/v1/shares/${id}/meta`, {
        headers: pw ? { 'X-Share-Password': pw } : {},
      });
      if (res.status === 401) {
        setNeedPassword(true);
        setError('This share needs a password.');
        return;
      }
      if (res.status === 410) {
        setError('This share expired (or was used up) and its bytes were purged.');
        return;
      }
      if (!res.ok) {
        setError('Share not found.');
        return;
      }
      setNeedPassword(false);
      setMeta(await res.json());
    },
    [id],
  );

  useEffect(() => {
    if (/^[0-9A-Za-z]{8,32}$/.test(id)) void load();
    else setError('Invalid share id.');
  }, [id, load]);

  async function download() {
    if (!meta) return;
    setDownloading(true);
    try {
      // Password travels in the header; the redirect target is a short-lived
      // HMAC/SigV4 URL, fetched here so the password never lands in an <a href>.
      const res = await fetch(`${API}/s/${meta.id}`, {
        headers: password ? { 'X-Share-Password': password } : {},
      });
      if (!res.ok) {
        setError(`Download failed (${res.status}). The link may be used up.`);
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = meta.filename;
      a.click();
      URL.revokeObjectURL(url);
      // Burn/max-views shares die server-side on read — refresh state.
      if (meta.burn || meta.maxViews !== null) void load(password || undefined);
    } finally {
      setDownloading(false);
    }
  }

  return (
    <>
      <h1>Share /s/{id}</h1>
      {error && <p>{error}</p>}
      {(needPassword || (meta?.hasPassword && !meta)) && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void load(password);
          }}
        >
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Share password" />
          <button type="submit">Unlock</button>
        </form>
      )}
      {meta && (
        <>
          <p>
            <strong>{meta.filename}</strong> · {(meta.size / 1048576).toFixed(1)} MB · {meta.mime}
          </p>
          <p>
            <strong>{meta.tier}</strong> <em>{meta.badge}</em> · residency {meta.residency}
            {meta.burn && ' · 🔥 burns after first read'}
            {meta.maxViews !== null && ` · ${meta.views}/${meta.maxViews} views`}
          </p>
          <p style={{ opacity: 0.7 }}>Expires {meta.expiresAt}</p>
          <button onClick={download} disabled={downloading}>
            {downloading ? 'Downloading…' : 'Download'}
          </button>
        </>
      )}
    </>
  );
}
