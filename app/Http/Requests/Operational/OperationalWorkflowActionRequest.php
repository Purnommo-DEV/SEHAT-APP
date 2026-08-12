<?php

namespace App\Http\Requests\Operational;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class OperationalWorkflowActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('event') instanceof Event;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
