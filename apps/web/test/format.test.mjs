import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { formatBytes, formatDate, daysLeft, tierTone } from '../lib/format.ts';

describe('format', () => {
  it('formats byte sizes', () => {
    assert.equal(formatBytes(0), '0 B');
    assert.equal(formatBytes(512), '512 B');
    assert.equal(formatBytes(1536), '1.5 KB');
    assert.equal(formatBytes(1048576), '1.0 MB');
    assert.equal(formatBytes(1073741824), '1.0 GB');
    assert.equal(formatBytes(-1), '–');
  });
  it('computes days left', () => {
    const now = Date.now();
    assert.equal(daysLeft(new Date(now + 7 * 86400000).toISOString(), now), 7);
    assert.equal(daysLeft(new Date(now - 1000).toISOString(), now), 0);
    assert.equal(daysLeft('nope'), null);
  });
  it('formats dates without throwing', () => {
    assert.equal(formatDate('nope'), 'nope');
    assert.match(formatDate(new Date(0).toISOString()), /\d{4}/);
  });
  it('tones tiers', () => {
    assert.equal(tierTone('L1'), 'edge');
    assert.equal(tierTone('L2'), 'vault');
    assert.equal(tierTone('L3'), 'vault');
  });
});
