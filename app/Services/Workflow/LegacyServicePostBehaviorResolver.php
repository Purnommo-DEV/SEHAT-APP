<?php

namespace App\Services\Workflow;

use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\ServicePost;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compatibility adapter for endpoints retained from the pre-workflow-builder UI.
 *
 * Records created after the workflow refactor are selected exclusively by behavior.
 * The type fallback only recognizes old records which still have the migration's
 * default confirmation behavior, so a newly configured post is never routed by type.
 */
class LegacyServicePostBehaviorResolver
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ServicePostBehavior $behavior): Builder
    {
        $legacyType = $this->legacyTypeFor($behavior);

        return $query->where(function (Builder $query) use ($behavior, $legacyType): void {
            $query->where('behavior', $behavior->value);

            if ($legacyType !== null) {
                $query->orWhere(function (Builder $query) use ($legacyType): void {
                    $query->where('behavior', ServicePostBehavior::ConfirmationOnly->value)
                        ->where('type', $legacyType->value);
                });
            }
        });
    }

    public function matches(ServicePost $servicePost, ServicePostBehavior $behavior): bool
    {
        if ($servicePost->behavior === $behavior) {
            return true;
        }

        $legacyType = $this->legacyTypeFor($behavior);

        return $legacyType !== null
            && $servicePost->behavior === ServicePostBehavior::ConfirmationOnly
            && $servicePost->type === $legacyType;
    }

    public function resolve(ServicePost $servicePost): ServicePostBehavior
    {
        foreach ([
            ServicePostBehavior::HealthForm,
            ServicePostBehavior::ScreeningForm,
            ServicePostBehavior::DonationForm,
        ] as $behavior) {
            if ($this->matches($servicePost, $behavior)) {
                return $behavior;
            }
        }

        return $servicePost->behavior;
    }

    private function legacyTypeFor(ServicePostBehavior $behavior): ?ServicePostType
    {
        return match ($behavior) {
            ServicePostBehavior::HealthForm => ServicePostType::Health,
            ServicePostBehavior::ScreeningForm => ServicePostType::Screening,
            ServicePostBehavior::DonationForm => ServicePostType::Donation,
            ServicePostBehavior::ConfirmationOnly,
            ServicePostBehavior::CustomForm => null,
        };
    }
}
