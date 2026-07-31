<?php

namespace App\Http\Requests\Participant;

use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;

class ParticipantSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Participant::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
