<?php

namespace App\Http\Requests\Health;

use App\Models\HealthAssessment;
use Illuminate\Foundation\Http\FormRequest;

class HealthQueueActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', HealthAssessment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
