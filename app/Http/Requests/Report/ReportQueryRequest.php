<?php

namespace App\Http\Requests\Report;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;

class ReportQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Report::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ];
    }
}
