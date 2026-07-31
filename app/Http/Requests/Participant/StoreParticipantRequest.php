<?php

namespace App\Http\Requests\Participant;

use App\Enums\ParticipantGender;
use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Participant::class) ?? false;
    }

    /**
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        return [
            'nik' => ['nullable', 'digits:16', Rule::unique('participants', 'nik')],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9]{8,20}$/'],
            'gender' => ['required', Rule::enum(ParticipantGender::class)],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $nik = preg_replace('/\\D/', '', (string) $this->input('nik'));
        $phone = preg_replace('/(?!^)\\+|[^0-9+]/', '', trim((string) $this->input('phone')));
        $name = preg_replace('/\\s+/', ' ', trim((string) $this->input('name')));

        $this->merge([
            'nik' => $nik === '' ? null : $nik,
            'phone' => $phone,
            'name' => $name,
        ]);
    }
}
