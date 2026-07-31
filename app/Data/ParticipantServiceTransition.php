<?php

namespace App\Data;

use App\Enums\ParticipantStatus;
use App\Models\ServicePost;

final readonly class ParticipantServiceTransition
{
    public function __construct(
        public ?ServicePost $nextServicePost,
        public ParticipantStatus $participantStatus,
    ) {}
}
