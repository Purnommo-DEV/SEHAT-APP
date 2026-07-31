<?php

namespace App\Data;

use App\Enums\EventStatus;
use App\Enums\ParticipantServiceType;
use App\Models\ServicePost;

final readonly class WorkflowValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  array<string, bool>  $availableServices
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public ?ServicePost $firstPost,
        public array $availableServices,
    ) {}

    public function serviceAvailable(ParticipantServiceType $service): bool
    {
        return $this->availableServices[$service->value] ?? false;
    }

    public function hasAvailableService(): bool
    {
        return in_array(true, $this->availableServices, true);
    }

    /**
     * @return array{
     *     available_services: array<string, bool>,
     *     errors: list<string>,
     *     has_available_service: bool,
     *     event_active: bool,
     *     event_status: string,
     *     event_status_label: string
     * }
     */
    public function toRegistrationState(EventStatus $eventStatus): array
    {
        return [
            'available_services' => $this->availableServices,
            'errors' => $this->errors,
            'has_available_service' => $this->hasAvailableService(),
            'event_active' => $eventStatus === EventStatus::Active,
            'event_status' => $eventStatus->value,
            'event_status_label' => $eventStatus->label(),
        ];
    }
}
