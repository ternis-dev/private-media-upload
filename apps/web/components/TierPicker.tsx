'use client';
import { TIERS, type TierId } from '../lib/tiers';

const tone: Record<TierId, { ring: string; pill: string; dot: string }> = {
  L1: {
    ring: 'aria-checked:border-amber-400/70',
    pill: 'bg-amber-500/10 text-amber-300 border-amber-500/30',
    dot: 'bg-amber-400',
  },
  L2: {
    ring: 'aria-checked:border-emerald-400/70',
    pill: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
    dot: 'bg-emerald-400',
  },
  L3: {
    ring: 'aria-checked:border-emerald-300/80',
    pill: 'bg-emerald-500/10 text-emerald-200 border-emerald-400/40',
    dot: 'bg-emerald-300',
  },
};

export function TierPicker({ value, onChange }: { value: TierId; onChange: (t: TierId) => void }) {
  return (
    <div role="radiogroup" aria-label="Privacy tier" className="grid gap-2 sm:grid-cols-3">
      {TIERS.map((t) => {
        const active = value === t.id;
        const c = tone[t.id];
        return (
          <button
            key={t.id}
            role="radio"
            aria-checked={active}
            onClick={() => onChange(t.id)}
            className={`rounded-xl border bg-panel p-3 text-left transition-colors ${active ? `border-emerald-400/70 bg-raised` : 'border-edge hover:border-mist/40'} ${c.ring}`}
          >
            <span className="flex items-center gap-2">
              <span className={`h-2 w-2 rounded-full ${c.dot}`} aria-hidden />
              <strong className="text-sm">{t.id}</strong>
              <span className="text-sm text-fog">{t.name}</span>
            </span>
            <span className={`mt-2 inline-block rounded-full border px-2 py-0.5 text-xs ${c.pill}`}>{t.badge}</span>
            <span className="mt-1 block text-xs text-mist">{t.hint}</span>
            {t.id === 'L2' && <span className="mt-1 block text-xs text-emerald-300/80">Recommended default</span>}
          </button>
        );
      })}
    </div>
  );
}
