<?php

namespace App\Data;

use App\Enums\ParticipantStatus;
use App\Models\ServicePost;

final readonly class ParticipantServiceRegistrationRoute
{
    public function __construct(
        public ServicePost $servicePost,
        public ParticipantStatus $participantStatus,
    ) {}
}
