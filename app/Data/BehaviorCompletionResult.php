<?php

namespace App\Data;

use App\Enums\ScreeningResult;
use Illuminate\Database\Eloquent\Model;

final readonly class BehaviorCompletionResult
{
    public function __construct(
        public ?Model $record = null,
        public bool $stopWorkflow = false,
        public ?ScreeningResult $screeningResult = null,
    ) {}
}
