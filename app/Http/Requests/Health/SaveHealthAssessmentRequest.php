<?php

namespace App\Http\Requests\Health;

use App\Models\HealthAssessment;
use Illuminate\Foundation\Http\FormRequest;

class SaveHealthAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('healthAssessment');

        if ($assessment instanceof HealthAssessment) {
            return $this->user()?->can('update', $assessment) ?? false;
        }

        return $this->user()?->can('create', HealthAssessment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('blood_pressure')) {
            $this->merge([
                'blood_pressure' => preg_replace('/\s+/', '', (string) $this->input('blood_pressure')),
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'blood_pressure' => ['nullable', 'string', 'max:15', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'blood_sugar' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'cholesterol' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'uric_acid' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blood_pressure.regex' => 'Tekanan darah harus menggunakan format sistolik/diastolik, contoh 120/80.',
        ];
    }
}
