export default async function SharePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const ok = /^[0-9A-Za-z]{8,32}$/.test(id);
  return (
    <>
      <h1>Share /s/{id}</h1>
      {!ok ? (
        <p>Invalid share id.</p>
      ) : (
        <p style={{ opacity: 0.7 }}>M0 stub — metadata + bytes stream land in M1 (see openapi.yaml).</p>
      )}
    </>
  );
}
