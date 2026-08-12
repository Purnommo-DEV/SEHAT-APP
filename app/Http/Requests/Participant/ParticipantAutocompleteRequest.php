<?php

namespace App\Http\Requests\Participant;

use App\Models\Event;
use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;

class ParticipantAutocompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('event') instanceof Event
            || ($this->user()?->can('viewAny', Participant::class) ?? false);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
