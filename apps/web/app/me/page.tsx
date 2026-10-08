'use client';
import { useState } from 'react';
import { formatBytes, formatDate } from '../../lib/format';
import { FileIcon } from '../../components/icons';
import { Alert, CopyField, btnDanger, btnGhost, btnPrimary, inputCls } from '../../components/ui';
import { apiFetch } from '../../lib/api';

interface Asset {
  id: string;
  filename: string;
  mime: string;
  size: number;
  share_id: string | null;
  expiresAt: string | null;
}

export default function MePage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [token, setToken] = useState<string | null>(null);
  const [assets, setAssets] = useState<Asset[]>([]);
  const [usage, setUsage] = useState(0);
  const [quota, setQuota] = useState(0);
  const [msg, setMsg] = useState('');
  const [msgTone, setMsgTone] = useState<'info' | 'error' | 'ok'>('info');

  const auth: Record<string, string> = token ? { Authorization: `Bearer ${token}` } : {};
  const quotaPct = quota > 0 ? Math.min(100, (usage / quota) * 100) : 0;

  function note(text: string, tone: 'info' | 'error' | 'ok' = 'info') {
    setMsg(text);
    setMsgTone(tone);
  }

  async function register() {
    const r = await apiFetch(`/api/v1/auth/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    note(r.ok ? 'Registered — now log in.' : `Register failed: ${await r.text()}`, r.ok ? 'ok' : 'error');
  }

  async function login() {
    const r = await apiFetch(`/api/v1/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password, name: 'web' }),
    });
    if (!r.ok) {
      note('Login failed — check email and password.', 'error');
      return;
    }
    const t = (await r.json()).token as string;
    setToken(t);
    note('Logged in.', 'ok');
    await refresh(t);
  }

  async function refresh(t: string = token ?? '') {
    const r = await apiFetch(`/api/v1/me/assets`, { headers: { Authorization: `Bearer ${t}` } });
    if (!r.ok) {
      note('Session expired — log in again.', 'error');
      setToken(null);
      return;
    }
    const j = await r.json();
    setAssets(j.assets);
    setUsage(j.usage);
    setQuota(j.quota);
  }

  async function exportZip() {
    const r = await apiFetch(`/api/v1/me/export?format=zip`, { headers: auth });
    if (!r.ok) {
      note(`Export failed (${r.status}).`, 'error');
      return;
    }
    const url = URL.createObjectURL(await r.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = 'privatewf-export.zip';
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
    note('Export downloaded (files + manifest.json).', 'ok');
  }

  async function deleteShare(a: Asset) {
    if (!a.share_id) return;
    if (!confirm(`Delete “${a.filename}” irreversibly?`)) return;
    const r = await apiFetch(`/api/v1/shares/${a.share_id}`, { method: 'DELETE', headers: auth });
    if (!r.ok) {
      note(`Delete failed (${r.status})${r.status === 401 ? ' — share may need its password' : ''}.`, 'error');
      return;
    }
    note('Share deleted — bytes purged immediately.', 'ok');
    await refresh();
  }

  async function deleteAccount() {
    if (!confirm('Delete your account and ALL files irreversibly?')) return;
    if (!confirm('Really sure? There is no undo.')) return;
    const r = await apiFetch(`/api/v1/me`, {
      method: 'DELETE',
      headers: { ...auth, 'Content-Type': 'application/json' },
      body: JSON.stringify({ password }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) {
      note(`Delete failed: ${j.error ?? r.status}.`, 'error');
      return;
    }
    note(`Account deleted (${j.deletedShares ?? 0} shares purged).`, 'ok');
    setToken(null);
    setAssets([]);
  }

  if (!token) {
    return (
      <div className="mx-auto max-w-md space-y-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">My files</h1>
          <p className="mt-1 text-sm text-mist">
            Accounts own uploads: quota, dashboard, full export & erasure. Anonymous uploads stay possible — no account needed to share.
          </p>
        </div>
        <div className="space-y-3 rounded-2xl border border-edge bg-panel p-4">
          <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="email" type="email" aria-label="Email" className={inputCls} />
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="password (min 12 chars)"
            aria-label="Password"
            className={inputCls}
          />
          <div className="flex gap-2">
            <button onClick={register} className={btnGhost}>
              Register
            </button>
            <button onClick={login} className={btnPrimary}>
              Log in
            </button>
          </div>
          <p className="text-xs text-mist">Passwords are argon2id-hashed. Login is rate-limited and never reveals whether an email exists.</p>
        </div>
        {msg && <Alert tone={msgTone === 'info' ? 'info' : msgTone}>{msg}</Alert>}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <h1 className="text-2xl font-bold tracking-tight">My files</h1>
        <button onClick={() => refresh()} className={btnGhost}>
          Refresh
        </button>
      </div>

      <section className="rounded-2xl border border-edge bg-panel p-4">
        <div className="flex justify-between text-sm">
          <span className="text-mist">Storage</span>
          <span>
            {formatBytes(usage)} of {formatBytes(quota)} ({Math.round(quotaPct)}%)
          </span>
        </div>
        <div className="mt-2 h-2 overflow-hidden rounded-full bg-edge" role="progressbar" aria-valuenow={Math.round(quotaPct)} aria-valuemin={0} aria-valuemax={100}>
          <div className={`h-full rounded-full ${quotaPct > 90 ? 'bg-red-400' : quotaPct > 70 ? 'bg-amber-400' : 'bg-emerald-400'}`} style={{ width: `${quotaPct}%` }} />
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          <button onClick={exportZip} className={btnGhost}>
            Export ZIP (GDPR)
          </button>
          <button onClick={deleteAccount} className={btnDanger}>
            Delete account…
          </button>
        </div>
      </section>

      {msg && <Alert tone={msgTone === 'info' ? 'info' : msgTone}>{msg}</Alert>}

      <section className="overflow-hidden rounded-2xl border border-edge bg-panel">
        {assets.length === 0 ? (
          <p className="p-6 text-center text-sm text-mist">
            Nothing here yet. <a href="/" className="text-emerald-300 underline-offset-2 hover:underline">Upload your first file</a> while logged in and it will appear here.
          </p>
        ) : (
          <ul className="divide-y divide-edge">
            {assets.map((a) => (
              <li key={a.id} className="flex items-center gap-3 p-3">
                <FileIcon mime={a.mime} className="h-8 w-8" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{a.filename}</p>
                  <p className="text-xs text-mist">
                    {formatBytes(a.size)}
                    {a.share_id ? (
                      <>
                        {' · '}<a href={`/s/${a.share_id}`} className="font-mono text-emerald-300 underline-offset-2 hover:underline">/s/{a.share_id}</a>
                        {a.expiresAt && <> · expires {formatDate(a.expiresAt)}</>}
                      </>
                    ) : (
                      ' · no share'
                    )}
                  </p>
                </div>
                {a.share_id && (
                  <button onClick={() => void deleteShare(a)} className={btnDanger} aria-label={`Delete ${a.filename}`}>
                    Delete
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      <CopyField value={email} label="Logged in as" />
    </div>
  );
}
