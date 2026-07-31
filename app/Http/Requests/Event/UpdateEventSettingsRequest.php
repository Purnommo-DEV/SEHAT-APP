<?php

namespace App\Http\Requests\Event;

use App\Enums\DonorNumberMode;
use App\Enums\RegistrationNumberFormat;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateEventSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && ($this->user()?->can('update', $event) ?? false);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'registration_number_format' => ['required', Rule::enum(RegistrationNumberFormat::class)],
            'registration_queue_prefix' => ['required', 'string', 'min:1', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
            'registration_male_prefix' => ['required', 'string', 'min:1', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
            'registration_female_prefix' => ['required', 'string', 'min:1', 'max:10', 'regex:/^[A-Z0-9-]+$/', 'different:registration_male_prefix'],
            'registration_queue_digits' => ['required', 'integer', 'min:1', 'max:6'],
            'donor_number_mode' => ['required', Rule::enum(DonorNumberMode::class)],
            'donor_queue_prefix' => ['required', 'string', 'min:1', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
            'donor_queue_digits' => ['required', 'integer', 'min:1', 'max:6'],
            'general_queue_digits' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'male_donor_queue_prefix' => ['required', 'string', 'min:1', 'max:5', 'regex:/^[A-Z0-9]+$/'],
            'female_donor_queue_prefix' => ['required', 'string', 'min:1', 'max:5', 'regex:/^[A-Z0-9]+$/', 'different:male_donor_queue_prefix'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'female_donor_queue_prefix.different' => 'Prefix antrean donor laki-laki dan perempuan harus berbeda.',
            'registration_female_prefix.different' => 'Prefix registrasi laki-laki dan perempuan harus berbeda.',
            'registration_queue_prefix.regex' => 'Prefix registrasi hanya boleh berisi huruf kapital, angka, dan tanda hubung.',
            'male_donor_queue_prefix.regex' => 'Prefix hanya boleh berisi huruf kapital dan angka.',
            'female_donor_queue_prefix.regex' => 'Prefix hanya boleh berisi huruf kapital dan angka.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'registration_queue_prefix' => Str::upper(trim((string) $this->input('registration_queue_prefix'))),
            'registration_male_prefix' => Str::upper(trim((string) $this->input('registration_male_prefix'))),
            'registration_female_prefix' => Str::upper(trim((string) $this->input('registration_female_prefix'))),
            'donor_queue_prefix' => Str::upper(trim((string) $this->input('donor_queue_prefix'))),
        ];

        if ($this->has('male_donor_queue_prefix')) {
            $normalized['male_donor_queue_prefix'] = Str::upper(trim((string) $this->input('male_donor_queue_prefix')));
        }

        if ($this->has('female_donor_queue_prefix')) {
            $normalized['female_donor_queue_prefix'] = Str::upper(trim((string) $this->input('female_donor_queue_prefix')));
        }

        $this->merge($normalized);
    }
}
