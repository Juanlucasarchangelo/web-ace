<?php

namespace App\Support;

use App\Models\AcePlan;
use InvalidArgumentException;

/** Price authority: stored server-side and copied to each payment order as a fixed snapshot. */
final class BillingPlans
{
    public const VERSION = 'db-2026-11-08';
    public const KEYS = ['free', 'cloud', 'pro'];

    public static function price(string $plan, string $cycle): int
    {
        if (! in_array($plan, self::KEYS, true) || ! in_array($cycle, ['monthly', 'annual'], true)) {
            throw new InvalidArgumentException('Plano ou periodicidade inválidos.');
        }
        $row = AcePlan::query()->whereKey($plan)->where('is_active', true)->first();
        if (! $row) throw new InvalidArgumentException('Plano indisponível para novas contratações.');
        return $cycle === 'monthly' ? $row->monthly_cents : $row->annual_cents;
    }

    public static function catalog(bool $includeInactive = false): array
    {
        $query = AcePlan::query()->orderBy('sort_order')->orderBy('id');
        if (! $includeInactive) $query->where('is_active', true);
        $plans = $query->get()->map(static fn (AcePlan $p): array => [
            'id' => $p->id,
            'name' => $p->title,
            'description' => $p->description ?? '',
            'features' => $p->features ?? [],
            'monthly_cents' => $p->monthly_cents,
            'annual_cents' => $p->annual_cents,
            'requires_payment' => $p->id !== 'free',
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ])->all();
        return [
            'currency' => 'BRL',
            'version' => self::VERSION,
            'plans' => $plans,
            'billing_policy' => 'Pagamento antecipado por ciclo; sem cobrança recorrente automática. Pedidos emitidos preservam os preços aprovados na emissão.',
        ];
    }
}
