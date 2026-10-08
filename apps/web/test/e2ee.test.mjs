import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { encryptFile, decryptContainer, parseShareKey, b64urlDecode, E2EE_CHUNK_BYTES } from '../lib/e2ee.ts';

const te = new TextEncoder();

describe('e2ee', () => {
  it('roundtrips small files with manifest', async () => {
    const data = te.encode('hello private world');
    const { container, keyB64 } = await encryptFile('hi.txt', 'text/plain', data);
    assert.equal(keyB64.length, 43);
    assert.notDeepEqual(container.subarray(28, 60), data.subarray(0, 32)); // opaque
    const dec = await decryptContainer(container, keyB64);
    assert.equal(dec.filename, 'hi.txt');
    assert.equal(dec.mime, 'text/plain');
    assert.deepEqual(dec.data, data);
  });

  it('roundtrips across chunk boundaries', async () => {
    const data = new Uint8Array(E2EE_CHUNK_BYTES + 12345);
    for (let off = 0; off < data.length; off += 65536) {
      data.set(crypto.getRandomValues(new Uint8Array(Math.min(65536, data.length - off))), off);
    }
    const { container, keyB64 } = await encryptFile('big.bin', 'application/octet-stream', data);
    const dec = await decryptContainer(container, keyB64);
    assert.deepEqual(dec.data, data);
  });

  it('roundtrips empty files', async () => {
    const { container, keyB64 } = await encryptFile('empty.bin', 'application/octet-stream', new Uint8Array(0));
    const dec = await decryptContainer(container, keyB64);
    assert.equal(dec.data.length, 0);
    assert.equal(dec.filename, 'empty.bin');
  });

  it('rejects wrong keys', async () => {
    const { container } = await encryptFile('a.txt', 'text/plain', te.encode('secret'));
    const other = await encryptFile('b.txt', 'text/plain', te.encode('other'));
    await assert.rejects(decryptContainer(container, other.keyB64), /wrong key or tampered/);
  });

  it('fails closed on tampered ciphertext', async () => {
    const { container, keyB64 } = await encryptFile('a.txt', 'text/plain', te.encode('secret-data-here'));
    const tampered = container.slice();
    tampered[tampered.length - 1] ^= 0xff;
    await assert.rejects(decryptContainer(tampered, keyB64), /wrong key or tampered/);
  });

  it('fails closed on truncated containers and bad magic', async () => {
    const { container, keyB64 } = await encryptFile('a.txt', 'text/plain', te.encode('x'));
    await assert.rejects(decryptContainer(container.subarray(0, 10), keyB64), /not a PWF1/);
    const bad = container.slice();
    bad[0] = 0x58;
    await assert.rejects(decryptContainer(bad, keyB64), /not a PWF1/);
  });

  it('parses keys from fragments', async () => {
    const { keyB64 } = await encryptFile('a.txt', 'text/plain', te.encode('x'));
    assert.equal(parseShareKey(`#k=${keyB64}`), keyB64);
    assert.equal(parseShareKey(`#foo=1&k=${keyB64}`), keyB64);
    assert.equal(parseShareKey('#k=short'), null);
    assert.equal(parseShareKey(''), null);
    assert.equal(b64urlDecode(keyB64).length, 32);
  });
});
