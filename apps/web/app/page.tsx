'use client';
import { useState } from 'react';
import { TIERS, type TierId } from '../lib/tiers';
import { CHUNK_BYTES, appendChunk, completeUpload, initUpload } from '../lib/uploads';
import { encryptFile } from '../lib/e2ee';
import { formatBytes } from '../lib/format';
import { TierPicker } from '../components/TierPicker';
import { Dropzone } from '../components/Dropzone';
import { Alert, CopyField, Field, ProgressBar, btnPrimary, inputCls } from '../components/ui';

type Phase = 'idle' | 'encrypting' | 'reserving' | 'uploading' | 'finalizing' | 'done' | 'error';

const PHASE_LABEL: Record<Phase, string> = {
  idle: '',
  encrypting: 'Encrypting in your browser…',
  reserving: 'Reserving upload…',
  uploading: 'Uploading…',
  finalizing: 'Verifying & creating link…',
  done: '',
  error: '',
};

export default function Home() {
  const [tier, setTier] = useState<TierId>('L2');
  const [file, setFile] = useState<File | null>(null);
  const [password, setPassword] = useState('');
  const [burn, setBurn] = useState(false);
  const [e2ee, setE2ee] = useState(false);
  const [phase, setPhase] = useState<Phase>('idle');
  const [percent, setPercent] = useState(0);
  const [share, setShare] = useState('');
  const [error, setError] = useState('');

  const busy = phase !== 'idle' && phase !== 'done' && phase !== 'error';
  const tierInfo = TIERS.find((t) => t.id === tier);

  async function upload() {
    if (!file || busy) return;
    setError('');
    setShare('');
    setPercent(0);
    try {
      let name = file.name;
      let mime = file.type || 'application/octet-stream';
      let bytes = new Uint8Array(await file.arrayBuffer());
      let keyFrag = '';
      if (e2ee) {
        if (file.size > 1024 * 1024 * 1024) throw new Error('E2EE is limited to 1 GB in this version.');
        setPhase('encrypting');
        const enc = await encryptFile(file.name, mime, bytes);
        bytes = enc.container;
        keyFrag = `#k=${enc.keyB64}`;
        name = `${file.name}.pwf1`;
        mime = 'application/octet-stream';
      }
      setPhase('reserving');
      const init = await initUpload({ tier, filename: name, size: bytes.length, mime });
      setPhase('uploading');
      let offset = 0;
      while (offset < bytes.length) {
        const p = await appendChunk(init.uploadId, new Blob([bytes.subarray(offset, offset + CHUNK_BYTES)]));
        offset = p.received;
        setPercent((offset / bytes.length) * 100);
      }
      setPhase('finalizing');
      const done = await completeUpload(init.uploadId, {
        password: password || undefined,
        burn: burn || undefined,
        e2ee: e2ee || undefined,
      });
      setShare(done.shareUrl + keyFrag);
      setPhase('done');
      setPercent(100);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
      setPhase('error');
    }
  }

  return (
    <div className="space-y-6">
      <section>
        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">Share files with an explicit privacy tier.</h1>
        <p className="mt-1 text-sm text-mist">
          Pick <em className="not-italic text-fog">where your bytes live</em> — global edge, German vault, or your own
          server — then add a password, burn-after-read, or end-to-end encryption.
        </p>
      </section>

      <section className="rounded-2xl border border-edge bg-panel p-4 sm:p-5">
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wider text-mist">1 · Privacy tier</h2>
        <TierPicker value={tier} onChange={setTier} />
        <p className="mt-2 text-xs text-mist">
          {tier === 'L1' && 'Fastest worldwide, replicated across regions — choose for non-sensitive files.'}
          {tier === 'L2' && 'Single German location, no CDN copies. The balanced default.'}
          {tier === 'L3' && 'Your own hardware, zero third-party custody. Slowest, most private.'}
        </p>
      </section>

      <section className="space-y-3 rounded-2xl border border-edge bg-panel p-4 sm:p-5">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-mist">2 · File & protection</h2>
        <Dropzone file={file} onFile={setFile} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Password (optional)" hint="Min 8 chars · argon2id-hashed, never stored in plain">
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" className={inputCls} />
          </Field>
          <div className="space-y-2 pt-6">
            <label className="flex cursor-pointer items-center gap-2 text-sm">
              <input type="checkbox" checked={burn} onChange={(e) => setBurn(e.target.checked)} className="h-4 w-4 accent-emerald-400" />
              Burn after first read
            </label>
            <label className="flex cursor-pointer items-start gap-2 text-sm">
              <input type="checkbox" checked={e2ee} onChange={(e) => setE2ee(e.target.checked)} className="mt-0.5 h-4 w-4 accent-emerald-400" />
              <span>
                End-to-end encrypt
                <span className="block text-xs text-mist">AES-256-GCM in this browser. The server only ever sees ciphertext; the key travels in the link fragment.</span>
              </span>
            </label>
          </div>
        </div>
      </section>

      <section className="rounded-2xl border border-edge bg-panel p-4 sm:p-5">
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wider text-mist">3 · Upload</h2>
        {phase !== 'done' && phase !== 'error' && phase !== 'idle' && (
          <div className="mb-3">
            <ProgressBar percent={percent} label={PHASE_LABEL[phase]} />
            {(phase === 'encrypting' || phase === 'uploading') && (
              <p className="mt-1 text-xs text-mist">
                <span className="pwf-spinner" aria-hidden /> {PHASE_LABEL[phase]} {phase === 'uploading' && `${Math.round(percent)}%`}
              </p>
            )}
          </div>
        )}
        {error && (
          <div className="mb-3">
            <Alert tone="error">{error}</Alert>
          </div>
        )}
        <button onClick={upload} disabled={!file || busy} className={btnPrimary}>
          {!file ? 'Choose a file first' : busy ? 'Working…' : `Upload ${formatBytes(file.size)} via ${tier}${e2ee ? ' · E2EE 🔒' : ''}`}
        </button>
        <p className="mt-2 text-xs text-mist">
          Uploading to: <strong className="text-fog">{tierInfo?.name}</strong> · {tierInfo?.badge}
        </p>
      </section>

      {phase === 'done' && share && (
        <section className="space-y-3 rounded-2xl border border-emerald-500/40 bg-emerald-500/5 p-4 sm:p-5">
          <h2 className="text-sm font-semibold uppercase tracking-wider text-emerald-300">Done ✓ — your private link</h2>
          <CopyField value={share} label="Share link" />
          {e2ee && (
            <Alert tone="warn">
              This link contains the decryption key after <code>#k=</code>. Share it <strong>whole</strong> — without the fragment nobody (including you) can read the file. Key loss = data loss.
            </Alert>
          )}
          <p>
            <a href={share} className="text-sm text-emerald-300 underline-offset-2 hover:underline">
              Open share page →
            </a>
          </p>
        </section>
      )}
    </div>
  );
}
