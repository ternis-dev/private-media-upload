import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { CHUNK_BYTES, chunkCount, parseShareId } from '../lib/uploads.ts';

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
});
