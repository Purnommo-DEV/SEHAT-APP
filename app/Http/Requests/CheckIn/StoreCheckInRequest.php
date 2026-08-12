<?php

namespace App\Http\Requests\CheckIn;

use App\Enums\ParticipantServiceType;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'participant_id' => ['required', 'integer', 'exists:participants,id'],
            'services' => ['required', 'array', 'min:1', 'max:'.count(ParticipantServiceType::cases())],
            'services.*' => ['required', 'distinct', Rule::enum(ParticipantServiceType::class)],
        ];
    }
}
