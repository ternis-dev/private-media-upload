import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: 'private.wf — private media sharing',
  description: 'Upload with an explicit privacy tier: R2 edge, DE vault, or sovereign node.',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body style={{ fontFamily: 'system-ui, sans-serif', maxWidth: 720, margin: '2rem auto', padding: '0 1rem' }}>
        <header>
          <strong>private.wf</strong> <span style={{ opacity: 0.6 }}>· M0 dev · ternis.dev</span>
        </header>
        <main>{children}</main>
      </body>
    </html>
  );
}
