<?php

namespace App\Data;

use App\Models\HealthAssessment;

final readonly class HealthAssessmentCompletionResult
{
    public function __construct(
        public HealthAssessment $assessment,
        public bool $alreadyCompleted,
    ) {}
}
