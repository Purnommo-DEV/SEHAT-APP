<?php

namespace App\Http\Requests\CheckIn;

use App\Enums\ParticipantServiceType;
use App\Models\Event;
use App\Models\EventParticipant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCheckInServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('event') instanceof Event
            && $this->route('eventParticipant') instanceof EventParticipant;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'services' => ['required', 'array', 'min:1', 'max:'.count(ParticipantServiceType::cases())],
            'services.*' => ['required', 'distinct', Rule::enum(ParticipantServiceType::class)],
        ];
    }

    /**
     * @return list<ParticipantServiceType>
     */
    public function serviceTypes(): array
    {
        return array_map(
            ParticipantServiceType::from(...),
            array_values($this->validated('services')),
        );
    }
}
