import type { Metadata } from 'next';
import Link from 'next/link';
import './globals.css';
import { ShieldMark } from '../components/icons';

export const metadata: Metadata = {
  title: 'private.wf — private media sharing',
  description: 'Upload with an explicit privacy tier: R2 edge, DE vault, or sovereign node. Optional end-to-end encryption.',
};

const API = process.env.API_BASE ?? 'http://localhost:8000';

async function apiHealth(): Promise<'up' | 'degraded' | 'down'> {
  try {
    const res = await fetch(`${API}/v1/health`, { cache: 'no-store', signal: AbortSignal.timeout(3000) });
    if (!res.ok) return 'down';
    const body = await res.json();
    return body.ok ? 'up' : 'degraded';
  } catch {
    return 'down';
  }
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const health = await apiHealth();
  const dot = health === 'up' ? 'bg-emerald-400' : health === 'degraded' ? 'bg-amber-400' : 'bg-red-400';

  return (
    <html lang="en">
      <body className="min-h-screen font-sans antialiased">
        <div className="mx-auto flex min-h-screen w-full max-w-3xl flex-col px-4">
          <header className="flex items-center justify-between py-5">
            <Link href="/" className="flex items-center gap-2 text-lg font-bold tracking-tight">
              <ShieldMark className="h-6 w-6 text-emerald-400" />
              private.wf
            </Link>
            <nav className="flex items-center gap-1 text-sm">
              <Link href="/" className="rounded-lg px-3 py-1.5 hover:bg-raised">
                Upload
              </Link>
              <Link href="/me" className="rounded-lg px-3 py-1.5 hover:bg-raised">
                My files
              </Link>
              <span title={`API status: ${health}`} className="ml-2 flex items-center gap-1.5 text-xs text-mist">
                <span className={`h-2 w-2 rounded-full ${dot}`} aria-hidden />
                {health}
              </span>
            </nav>
          </header>
          <main className="flex-1 pb-10">{children}</main>
          <footer className="border-t border-edge py-4 text-xs text-mist">
            <span>
              by <a href="https://ternis.dev" className="underline-offset-2 hover:underline">ternis.dev</a> · AGPL-3.0 open source
            </span>
            <span className="float-right">DE/EU-first · no trackers · no index</span>
          </footer>
        </div>
      </body>
    </html>
  );
}
