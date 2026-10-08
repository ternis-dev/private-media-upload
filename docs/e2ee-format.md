# E2EE container format v1 (`PWF1`)

Goal: server stores **opaque ciphertext only**. Key lives in the share URL
fragment (`#k=<base64url>`) and never reaches the server (fragments are
never sent over HTTP).

## Crypto
- Cipher: AES-256-GCM, 256-bit random key per file (generated in browser).
- Nonce: 96-bit base random per file; per-chunk nonce = `base XOR BE64(index)`
  in the last 8 bytes. Unique (key, nonce) per chunk by construction.
- Chunk plaintext: 4 MiB (last shorter), except chunk 0 (manifest, small).
- GCM auth tag (128-bit) per chunk: any tampering fails closed at decrypt.

## Layout (all integers big-endian)
```
magic:        4 bytes  "PWF1"
chunk_size:   u32      (plaintext chunk bytes, echo of offer)
nonce_base:   12 bytes random
orig_size:    u64      total plaintext length (manifest + file bytes)
chunks:       repeat { len: u32, ciphertext: len bytes }
chunk 0 plaintext: JSON {"v":1,"filename":"…","mime":"…","size":N}
chunks 1..n plaintext: file bytes (concatenated == N bytes)
```

## Verification on decrypt
1. magic + version check; 2. every chunk GCM-verified (throws on tamper);
3. manifest parsed; 4. reassembled length == manifest.size == orig_size.
Filename/mime come from the *encrypted* manifest — the server only ever
sees `application/octet-stream` and the `e2ee: true` flag.

## Limits (M3)
- Whole-container download then incremental decrypt (Blob parts): E2EE UI
  recommends ≤1 GB until streaming-decrypt lands.
- No server-side AV on ciphertext (quarantine worker skips `e2ee` assets).
  Malware inside E2EE shares is the recipient's responsibility — stated in UI.
- Key loss = data loss. No recovery, no password reset for E2EE links.
