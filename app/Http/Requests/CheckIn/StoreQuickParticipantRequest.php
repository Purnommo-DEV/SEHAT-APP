<?php

namespace App\Http\Requests\CheckIn;

use App\Enums\ParticipantGender;
use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuickParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionName::ManageCheckIn->value) ?? false;
    }

    /**
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{8,20}$/'],
            'gender' => ['sometimes', Rule::enum(ParticipantGender::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/(?!^)\+|[^0-9+]/', '', trim((string) $this->input('phone')));
        $name = preg_replace('/\s+/', ' ', trim((string) $this->input('name')));

        $this->merge([
            'name' => $name,
            'phone' => $phone === '' ? null : $phone,
            // Gender remains a domain invariant for registration prefixes and
            // separated donor lanes. The one-tap UI defaults to male and lets
            // the operator switch it without opening the full participant form.
            'gender' => $this->input('gender', ParticipantGender::Male->value),
        ]);
    }
}
