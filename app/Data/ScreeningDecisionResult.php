<?php

namespace App\Data;

use App\Models\DonorScreening;
use App\Models\QueueTicket;

final readonly class ScreeningDecisionResult
{
    public function __construct(
        public DonorScreening $screening,
        public bool $alreadyDecided,
        public ?QueueTicket $donorQueueTicket,
    ) {}
}
