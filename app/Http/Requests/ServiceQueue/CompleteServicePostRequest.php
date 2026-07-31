<?php

namespace App\Http\Requests\ServiceQueue;

use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Models\ServicePost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteServicePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('queues.manage') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var ServicePost $servicePost */
        $servicePost = $this->route('servicePost');

        return match ($servicePost->behavior) {
            ServicePostBehavior::HealthForm => [
                'blood_pressure' => ['nullable', 'string', 'max:15', 'regex:/^\d{2,3}\/\d{2,3}$/'],
                'blood_sugar' => ['nullable', 'numeric', 'between:0,2000'],
                'cholesterol' => ['nullable', 'numeric', 'between:0,2000'],
                'uric_acid' => ['nullable', 'numeric', 'between:0,100'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            ServicePostBehavior::ScreeningForm => [
                'result' => ['required', Rule::enum(ScreeningResult::class)],
                'reason' => ['nullable', 'required_if:result,'.ScreeningResult::NotEligible->value, 'string', 'max:500'],
            ],
            ServicePostBehavior::DonationForm => [
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            ServicePostBehavior::CustomForm => [
                'notes' => ['required', 'string', 'max:5000'],
            ],
            ServicePostBehavior::ConfirmationOnly => [],
        };
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('blood_pressure')) {
            $this->merge([
                'blood_pressure' => preg_replace('/\s+/', '', (string) $this->input('blood_pressure')),
            ]);
        }

        if ($this->has('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blood_pressure.regex' => 'Tekanan darah harus menggunakan format sistolik/diastolik, contoh 120/80.',
            'reason.required_if' => 'Alasan wajib diisi jika peserta tidak layak.',
        ];
    }
}
