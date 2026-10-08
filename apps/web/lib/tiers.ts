export type TierId = 'L1' | 'L2' | 'L3';

export const TIERS: { id: TierId; name: string; badge: string; hint: string }[] = [
  { id: 'L1', name: 'Edge Object Storage', badge: 'Global edge 🌍', hint: 'R2/S3 · fastest · replicated' },
  { id: 'L2', name: 'Provider Vault DE', badge: 'DE-only 🇩🇪', hint: 'SFTP · single location · no CDN' },
  { id: 'L3', name: 'Sovereign Node', badge: 'DE-only 🇩🇪 · sovereign', hint: 'own server · slowest · highest privacy' },
];

export function defaultExpiryDays(tier: TierId): number {
  return tier === 'L3' ? 30 : 7;
}
