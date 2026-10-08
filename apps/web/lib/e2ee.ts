// M3: browser AES-GCM E2EE (key in #fragment, never sent to server).
// M0 stub: documents the intended API surface so web+api stay aligned.
export async function e2eeEncrypt(_bytes: Uint8Array): Promise<{ ciphertext: Uint8Array; keyFragment: string }> {
  throw new Error('E2EE lands in M3 — see .plans/01-privacy-tiers.md');
}
export async function e2eeDecrypt(_ciphertext: Uint8Array, _keyFragment: string): Promise<Uint8Array> {
  throw new Error('E2EE lands in M3 — see .plans/01-privacy-tiers.md');
}
