'use client';
import { useState } from 'react';

export function Alert({ tone = 'info', children }: { tone?: 'info' | 'warn' | 'error' | 'ok'; children: React.ReactNode }) {
  const tones = {
    info: 'border-edge bg-panel text-fog',
    warn: 'border-amber-500/40 bg-amber-500/10 text-amber-200',
    error: 'border-red-500/40 bg-red-500/10 text-red-200',
    ok: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200',
  } as const;
  return <div className={`rounded-lg border px-3 py-2 text-sm ${tones[tone]}`}>{children}</div>;
}

export function ProgressBar({ percent, label }: { percent: number; label?: string }) {
  const p = Math.max(0, Math.min(100, percent));
  return (
    <div>
      <div className="h-2 overflow-hidden rounded-full bg-edge" role="progressbar" aria-valuenow={Math.round(p)} aria-valuemin={0} aria-valuemax={100}>
        <div className="h-full rounded-full bg-emerald-400 transition-all" style={{ width: `${p}%` }} />
      </div>
      {label && <p className="mt-1 text-xs text-mist">{label}</p>}
    </div>
  );
}

export function CopyField({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = useState(false);
  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
    } catch {
      const ta = document.createElement('textarea');
      ta.value = value;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }
  return (
    <div>
      <span className="mb-1 block text-xs text-mist">{label}</span>
      <div className="flex gap-2">
        <input readOnly value={value} onFocus={(e) => e.target.select()} className="min-w-0 flex-1 rounded-lg border border-edge bg-void px-3 py-2 font-mono text-sm text-fog" />
        <button onClick={copy} className="shrink-0 rounded-lg border border-edge bg-raised px-3 py-2 text-sm hover:border-emerald-500/50">
          {copied ? 'Copied ✓' : 'Copy'}
        </button>
      </div>
    </div>
  );
}

export function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-fog">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-mist">{hint}</span>}
    </label>
  );
}

export const inputCls =
  'w-full rounded-lg border border-edge bg-void px-3 py-2 text-sm text-fog placeholder:text-mist/60 focus:border-emerald-500/60 focus:outline-none';

export const btnPrimary =
  'rounded-lg bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50';

export const btnGhost =
  'rounded-lg border border-edge bg-raised px-4 py-2 text-sm text-fog hover:border-emerald-500/50';

export const btnDanger =
  'rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-1.5 text-sm text-red-200 hover:bg-red-500/20';
