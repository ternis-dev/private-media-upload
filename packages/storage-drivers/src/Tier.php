<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

enum Tier: string
{
    case L1 = 'L1';
    case L2 = 'L2';
    case L3 = 'L3';

    public function label(): string
    {
        return match ($this) {
            self::L1 => 'Edge Object Storage',
            self::L2 => 'Provider Vault DE',
            self::L3 => 'Sovereign Node',
        };
    }

    public function residency(): string
    {
        return match ($this) {
            self::L1 => 'global-edge',
            self::L2 => 'DE-only',
            self::L3 => 'DE-only',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::L1 => 'Global edge 🌍',
            self::L2 => 'DE-only 🇩🇪',
            self::L3 => 'DE-only 🇩🇪 · sovereign',
        };
    }
}
