import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { CHUNK_BYTES, chunkCount, parseShareId, completeUpload, initUpload } from '../lib/uploads.ts';

describe('uploads', () => {
  it('chunks in 4 MB units', () => {
    assert.equal(CHUNK_BYTES, 4 * 1024 * 1024);
    assert.equal(chunkCount(0), 0);
    assert.equal(chunkCount(1), 1);
    assert.equal(chunkCount(CHUNK_BYTES), 1);
    assert.equal(chunkCount(CHUNK_BYTES + 1), 2);
    assert.equal(chunkCount(100 * 1024 * 1024), 25);
  });
  it('parses share ids from urls or bare ids', () => {
    assert.equal(parseShareId('wWZ72LcxVgMZ'), 'wWZ72LcxVgMZ');
    assert.equal(parseShareId('http://localhost:3000/s/wWZ72LcxVgMZ'), 'wWZ72LcxVgMZ');
    assert.equal(parseShareId('https://private.wf/s/abcDEF123456?x=1'), 'abcDEF123456');
    assert.equal(parseShareId('../etc/passwd'), null);
    assert.equal(parseShareId('short'), null);
  });
  it('complete forwards share options as JSON', async () => {
    const seen = [];
    const orig = globalThis.fetch;
    globalThis.fetch = async (url, init) => {
      seen.push([url, init]);
      return new Response(JSON.stringify({ shareId: 'x'.repeat(12), shareUrl: 'http://localhost:3000/s/' + 'x'.repeat(12) }), { status: 201 });
    };
    try {
      await completeUpload('up_' + 'y'.repeat(16), { password: 'pw-12345678', burn: true });
    } finally {
      globalThis.fetch = orig;
    }
    assert.equal(seen.length, 1);
    assert.equal(seen[0][0], '/api/v1/uploads/up_yyyyyyyyyyyyyyyy/complete');
    assert.deepEqual(JSON.parse(seen[0][1].body), { password: 'pw-12345678', burn: true });
  });
  it('init posts to the same-origin proxy', async () => {
    const seen = [];
    const orig = globalThis.fetch;
    globalThis.fetch = async (url, init) => {
      seen.push([url, init]);
      return new Response(JSON.stringify({ uploadId: 'up_' + 'z'.repeat(16) }), { status: 201 });
    };
    try {
      await initUpload({ tier: 'L2', filename: 'a.bin', size: 1, mime: 'application/octet-stream' });
    } finally {
      globalThis.fetch = orig;
    }
    assert.equal(seen[0][0], '/api/v1/uploads/init');
  });
});
