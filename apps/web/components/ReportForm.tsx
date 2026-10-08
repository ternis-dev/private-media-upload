'use client';
import { useState } from 'react';
import { btnGhost, btnPrimary, inputCls, Alert } from './ui';

const REASONS = ['csam', 'terror', 'copyright', 'malware', 'other'] as const;

export function ReportForm({ api, shareId }: { api: string; shareId: string }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState<string>('other');
  const [contact, setContact] = useState('');
  const [done, setDone] = useState(false);
  const [error, setError] = useState('');

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    const res = await fetch(`${api}/v1/shares/${shareId}/report`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reason, contact: contact || undefined }),
    });
    if (!res.ok) {
      setError(`Report failed (${res.status}).`);
      return;
    }
    setDone(true);
  }

  if (!open)
    return (
      <button onClick={() => setOpen(true)} className="text-xs text-mist underline-offset-2 hover:text-fog hover:underline">
        Report abuse
      </button>
    );
  if (done) return <Alert tone="ok">Report received. Our team reviews every report before acting.</Alert>;

  return (
    <form onSubmit={submit} className="space-y-2 rounded-lg border border-edge bg-panel p-3">
      <p className="text-sm font-medium">Report this share</p>
      <div className="flex flex-col gap-2 sm:flex-row">
        <select value={reason} onChange={(e) => setReason(e.target.value)} className={inputCls} aria-label="Reason">
          {REASONS.map((r) => (
            <option key={r} value={r}>
              {r}
            </option>
          ))}
        </select>
        <input value={contact} onChange={(e) => setContact(e.target.value)} placeholder="Contact email (optional)" type="email" className={inputCls} />
      </div>
      {error && <Alert tone="error">{error}</Alert>}
      <div className="flex gap-2">
        <button type="submit" className={btnPrimary}>
          Send report
        </button>
        <button type="button" onClick={() => setOpen(false)} className={btnGhost}>
          Cancel
        </button>
      </div>
    </form>
  );
}
