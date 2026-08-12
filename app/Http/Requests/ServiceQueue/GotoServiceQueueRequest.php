<?php

namespace App\Http\Requests\ServiceQueue;

use App\Enums\ParticipantGender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GotoServiceQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'queue_lane' => ['nullable', Rule::enum(ParticipantGender::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'queue_lane.enum' => 'Jalur antrean tidak valid.',
        ];
    }
}
