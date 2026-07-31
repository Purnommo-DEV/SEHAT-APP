<?php

namespace App\Services\Workflow;

use App\Enums\ServicePostBehavior;
use App\Services\Workflow\Behaviors\ConfirmationOnlyBehavior;
use App\Services\Workflow\Behaviors\CustomFormBehavior;
use App\Services\Workflow\Behaviors\DonationFormBehavior;
use App\Services\Workflow\Behaviors\HealthFormBehavior;
use App\Services\Workflow\Behaviors\ScreeningFormBehavior;
use App\Services\Workflow\Behaviors\ServicePostBehaviorStrategy;

class ServicePostBehaviorResolver
{
    public function __construct(
        private readonly ConfirmationOnlyBehavior $confirmationOnly,
        private readonly HealthFormBehavior $healthForm,
        private readonly ScreeningFormBehavior $screeningForm,
        private readonly DonationFormBehavior $donationForm,
        private readonly CustomFormBehavior $customForm,
    ) {}

    public function resolve(ServicePostBehavior $behavior): ServicePostBehaviorStrategy
    {
        return match ($behavior) {
            ServicePostBehavior::ConfirmationOnly => $this->confirmationOnly,
            ServicePostBehavior::HealthForm => $this->healthForm,
            ServicePostBehavior::ScreeningForm => $this->screeningForm,
            ServicePostBehavior::DonationForm => $this->donationForm,
            ServicePostBehavior::CustomForm => $this->customForm,
        };
    }
}
