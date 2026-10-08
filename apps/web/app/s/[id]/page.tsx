'use client';
import { use, useCallback, useEffect, useState } from 'react';
import { decryptContainer, parseShareKey } from '../../../lib/e2ee';
import { formatBytes, formatDate, daysLeft } from '../../../lib/format';
import { FileIcon } from '../../../components/icons';
import { Alert, CopyField, btnPrimary, inputCls } from '../../../components/ui';
import { ReportForm } from '../../../components/ReportForm';

const API = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8000';

interface Meta {
  id: string;
  tier: string;
  badge: string;
  residency: string;
  filename: string;
  mime: string;
  size: number;
  sha256: string;
  expiresAt: string;
  hasPassword: boolean;
  views: number;
  maxViews: number | null;
  burn: boolean;
  e2ee: boolean;
  hasThumbnail: boolean;
}

export default function SharePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const [password, setPassword] = useState('');
  const [needPassword, setNeedPassword] = useState(false);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [error, setError] = useState('');
  const [downloading, setDownloading] = useState(false);
  const [thumbUrl, setThumbUrl] = useState<string | null>(null);

  const load = useCallback(
    async (pw?: string) => {
      setError('');
      const res = await fetch(`${API}/v1/shares/${id}/meta`, {
        headers: pw ? { 'X-Share-Password': pw } : {},
      });
      if (res.status === 401) {
        setNeedPassword(true);
        setError('This share is password-protected.');
        return;
      }
      if (res.status === 410) {
        setError('This share expired, was revoked, or is used up — its bytes were purged.');
        return;
      }
      if (!res.ok) {
        setError('Share not found. Links are case-sensitive and 12 characters.');
        return;
      }
      setNeedPassword(false);
      const m = (await res.json()) as Meta;
      setMeta(m);
      if (m.hasThumbnail && !m.e2ee) {
        const tr = await fetch(`${API}/s/${m.id}?thumb=1`, {
          headers: pw ? { 'X-Share-Password': pw } : {},
        });
        if (tr.ok) setThumbUrl(URL.createObjectURL(await tr.blob()));
      }
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
    setError('');
    try {
      const res = await fetch(`${API}/s/${meta.id}`, {
        headers: password ? { 'X-Share-Password': password } : {},
      });
      if (res.status === 401) {
        setNeedPassword(true);
        setError('Password required for download.');
        return;
      }
      if (!res.ok) {
        setError(`Download failed (${res.status}). The link may be used up.`);
        return;
      }
      if (meta.e2ee) {
        const key = parseShareKey(window.location.hash);
        if (!key) {
          setError('End-to-end encrypted share: open the full link including #k=… — the key never leaves your browser.');
          return;
        }
        const dec = await decryptContainer(new Uint8Array(await res.arrayBuffer()), key);
        triggerDownload(new Blob([dec.data as BlobPart], { type: dec.mime }), dec.filename);
      } else {
        triggerDownload(await res.blob(), meta.filename);
      }
      if (meta.burn || meta.maxViews !== null) void load(password || undefined);
    } catch (e) {
      setError(`Failed: ${e instanceof Error ? e.message : String(e)}`);
    } finally {
      setDownloading(false);
    }
  }

  function triggerDownload(blob: Blob, name: string) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
  }

  return (
    <div className="space-y-4">
      <p className="font-mono text-xs text-mist">
        private.wf <span aria-hidden>/</span> s <span aria-hidden>/</span> <span className="text-fog">{id}</span>
      </p>

      {error && <Alert tone={needPassword ? 'warn' : 'error'}>{error}</Alert>}

      {(needPassword || (meta?.hasPassword && !meta)) && !meta && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void load(password);
          }}
          className="flex gap-2 rounded-2xl border border-edge bg-panel p-4"
        >
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Share password"
            aria-label="Share password"
            className={inputCls}
          />
          <button type="submit" className={btnPrimary}>
            Unlock
          </button>
        </form>
      )}

      {meta && (
        <article className="overflow-hidden rounded-2xl border border-edge bg-panel">
          {thumbUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={thumbUrl} alt={`Preview of ${meta.filename}`} className="max-h-80 w-full object-cover" />
          ) : (
            <div className="flex items-center gap-3 border-b border-edge bg-raised/50 p-4">
              <FileIcon mime={meta.mime} />
              <div className="min-w-0">
                <p className="truncate font-medium">{meta.filename}</p>
                <p className="text-xs text-mist">
                  {formatBytes(meta.size)} · {meta.mime}
                </p>
              </div>
            </div>
          )}

          <div className="space-y-3 p-4">
            <div className="flex flex-wrap gap-1.5 text-xs">
              <span className="rounded-full border border-edge bg-raised px-2 py-0.5">
                <strong>{meta.tier}</strong> · {meta.badge}
              </span>
              <span className="rounded-full border border-edge bg-raised px-2 py-0.5">residency: {meta.residency}</span>
              {meta.e2ee && (
                <span className="rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2 py-0.5 text-emerald-200">
                  🔒 end-to-end encrypted
                </span>
              )}
              {meta.burn && (
                <span className="rounded-full border border-red-500/40 bg-red-500/10 px-2 py-0.5 text-red-200">
                  🔥 burns after first read
                </span>
              )}
              {meta.maxViews !== null && (
                <span className="rounded-full border border-edge bg-raised px-2 py-0.5">
                  {meta.views}/{meta.maxViews} views
                </span>
              )}
            </div>

            <dl className="grid grid-cols-2 gap-2 text-sm">
              <div>
                <dt className="text-xs text-mist">Expires</dt>
                <dd>
                  {formatDate(meta.expiresAt)}{' '}
                  <span className="text-xs text-mist">
                    ({(() => {
                      const d = daysLeft(meta.expiresAt);
                      return d === null ? '' : d === 0 ? 'today' : `in ${d}d`;
                    })()})
                  </span>
                </dd>
              </div>
              <div>
                <dt className="text-xs text-mist">Integrity</dt>
                <dd className="font-mono text-xs" title={meta.sha256}>
                  sha256:{meta.sha256.slice(0, 16)}…
                </dd>
              </div>
            </dl>

            {meta.e2ee && !parseShareKey(typeof window !== 'undefined' ? window.location.hash : '') && (
              <Alert tone="warn">
                No decryption key in this URL. Open the <strong>complete</strong> link including{' '}
                <code>#k=…</code> — without it, not even we can read this file.
              </Alert>
            )}

            <div className="flex flex-wrap items-center gap-2">
              <button onClick={download} disabled={downloading} className={btnPrimary}>
                {downloading ? 'Downloading…' : 'Download'}
              </button>
              <CopyField
                value={typeof window !== 'undefined' ? window.location.href : `/s/${meta.id}`}
                label="Copy link (include the #k= part — it is the decryption key)"
              />
            </div>

            <div className="border-t border-edge pt-2">
              <ReportForm api={API} shareId={meta.id} />
            </div>
          </div>
        </article>
      )}
    </div>
  );
}
