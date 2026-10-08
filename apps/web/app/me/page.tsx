'use client';
import { useState } from 'react';

const API = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8000';

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

  const auth = token ? { Authorization: `Bearer ${token}` } : {};

  async function register() {
    const r = await fetch(`${API}/v1/auth/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    setMsg(r.ok ? 'registered — now log in' : `register failed: ${await r.text()}`);
  }

  async function login() {
    const r = await fetch(`${API}/v1/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password, name: 'web' }),
    });
    if (!r.ok) {
      setMsg('login failed (check email/password)');
      return;
    }
    const t = (await r.json()).token as string;
    setToken(t);
    setMsg('logged in');
    await refresh(t);
  }

  async function refresh(t: string = token ?? '') {
    const r = await fetch(`${API}/v1/me/assets`, { headers: { Authorization: `Bearer ${t}` } });
    if (!r.ok) {
      setMsg('session expired — log in again');
      setToken(null);
      return;
    }
    const j = await r.json();
    setAssets(j.assets);
    setUsage(j.usage);
    setQuota(j.quota);
  }

  async function exportZip() {
    const r = await fetch(`${API}/v1/me/export?format=zip`, { headers: auth });
    const blob = await r.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'privatewf-export.zip';
    a.click();
    URL.revokeObjectURL(url);
  }

  async function deleteAccount() {
    if (!confirm('Delete account and ALL files irreversibly?')) return;
    const r = await fetch(`${API}/v1/me`, {
      method: 'DELETE',
      headers: { ...auth, 'Content-Type': 'application/json' },
      body: JSON.stringify({ password }),
    });
    setMsg(r.ok ? 'account deleted' : `delete failed: ${await r.text()}`);
    if (r.ok) {
      setToken(null);
      setAssets([]);
    }
  }

  if (!token) {
    return (
      <>
        <h1>Account</h1>
        <p style={{ opacity: 0.7 }}>Accounts own uploads (quota, dashboard, full erasure). Anonymous uploads stay possible.</p>
        <div>
          <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="email" />
        </div>
        <div>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="password (min 12 chars)" />
        </div>
        <button onClick={register}>Register</button> <button onClick={login}>Log in</button>
        <pre>{msg}</pre>
      </>
    );
  }

  return (
    <>
      <h1>My files</h1>
      <p>
        {(usage / 1048576).toFixed(1)} / {(quota / 1073741824).toFixed(1)} GB used
      </p>
      <button onClick={() => void refresh()}>Refresh</button>{' '}
      <button onClick={exportZip}>Export ZIP (GDPR)</button>{' '}
      <button onClick={deleteAccount}>Delete account…</button>
      <ul>
        {assets.map((a) => (
          <li key={a.id}>
            {a.filename} ({(a.size / 1024).toFixed(0)} KB)
            {a.share_id && (
              <>
                {' — '}<a href={`/s/${a.share_id}`}>/s/{a.share_id}</a>
              </>
            )}
          </li>
        ))}
      </ul>
      <pre>{msg}</pre>
    </>
  );
}
