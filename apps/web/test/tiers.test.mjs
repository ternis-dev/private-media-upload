import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { TIERS, defaultExpiryDays } from '../lib/tiers.ts';

describe('tiers', () => {
  it('exposes L1/L2/L3 with residency badges', () => {
    assert.deepEqual(TIERS.map((t) => t.id), ['L1', 'L2', 'L3']);
    assert.ok(TIERS[0].badge.includes('Global'));
    assert.ok(TIERS[1].badge.includes('DE-only'));
    assert.ok(TIERS[2].badge.includes('sovereign'));
  });
  it('L3 keeps longer default expiry', () => {
    assert.ok(defaultExpiryDays('L3') > defaultExpiryDays('L1'));
  });
});
