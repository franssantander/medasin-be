<?php

namespace App\Data\Plan;

use App\Models\Plan;
use Spatie\LaravelData\Data;

class PlanData extends Data
{
    /**
     * @param  array{projects: ?int, areas: ?int, resources: ?int, storage_mb: ?int}  $limits
     * @param  list<string>  $features
     */
    public function __construct(
        public string $uuid,
        public string $slug,
        public string $name,
        public string $description,
        public int $price_minor,
        public string $currency,
        public ?string $billing_interval,
        public array $limits,
        public array $features,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        $limits = [
            'projects' => $plan->limits['projects'] ?? null,
            'areas' => $plan->limits['areas'] ?? null,
            'resources' => $plan->limits['resources'] ?? null,
            'storage_mb' => $plan->limits['storage_mb'] ?? null,
        ];

        return new self(
            uuid: $plan->uuid,
            slug: $plan->slug,
            name: $plan->name,
            description: $plan->description,
            price_minor: $plan->price_minor,
            currency: $plan->currency->value,
            billing_interval: $plan->billing_interval?->value,
            limits: $limits,
            features: self::features($limits),
        );
    }

    /**
     * Customer-facing lines derived from the limits, so copy cannot drift from entitlements.
     *
     * @param  array{projects: ?int, areas: ?int, resources: ?int, storage_mb: ?int}  $limits
     * @return list<string>
     */
    private static function features(array $limits): array
    {
        $features = [];
        foreach (['projects', 'areas', 'resources'] as $feature) {
            $features[] = $limits[$feature] === null
                ? "Unlimited {$feature}"
                : 'Up to '.number_format($limits[$feature])." {$feature}";
        }
        $storage = $limits['storage_mb'];
        $features[] = match (true) {
            $storage === null => 'Unlimited storage',
            $storage >= 1024 && $storage % 1024 === 0 => number_format($storage / 1024).' GB storage',
            default => number_format($storage).' MB storage',
        };
        $features[] = 'Every tool: Boards, Focus, Habits, Notes, Journal, Letters, and Calendar';

        return $features;
    }
}
