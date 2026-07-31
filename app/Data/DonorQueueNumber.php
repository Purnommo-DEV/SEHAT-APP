<?php

namespace App\Data;

use App\Enums\QueueType;

final readonly class DonorQueueNumber
{
    public function __construct(
        public QueueType $queueType,
        public int $number,
    ) {}
}
