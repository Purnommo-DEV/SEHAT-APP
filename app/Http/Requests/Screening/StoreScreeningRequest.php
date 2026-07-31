<?php

namespace App\Http\Requests\Screening;

use App\Enums\ScreeningResult;
use App\Models\DonorScreening;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScreeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DonorScreening::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => trim((string) $this->input('reason')) ?: null]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::enum(ScreeningResult::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
