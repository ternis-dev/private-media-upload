const API = process.env.API_BASE ?? 'http://localhost:8000';

interface Meta {
  id: string;
  tier: string;
  badge: string;
  residency: string;
  filename: string;
  mime: string;
  size: number;
  expiresAt: string;
}

export default async function SharePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^[0-9A-Za-z]{8,32}$/.test(id)) return <p>Invalid share id.</p>;

  const res = await fetch(`${API}/v1/shares/${id}/meta`, { cache: 'no-store' });
  if (res.status === 410) return <p>This share expired and its bytes were purged.</p>;
  if (!res.ok) return <p>Share not found.</p>;
  const meta = (await res.json()) as Meta;

  return (
    <>
      <h1>{meta.filename}</h1>
      <p>
        <strong>{meta.tier}</strong> <em>{meta.badge}</em> · {(meta.size / 1048576).toFixed(1)} MB · {meta.mime}
      </p>
      <p style={{ opacity: 0.7 }}>
        Residency: {meta.residency} · expires {meta.expiresAt}
      </p>
      <p>
        <a href={`${API}/s/${meta.id}`}>Download</a>
      </p>
    </>
  );
}
