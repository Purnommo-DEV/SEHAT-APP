<?php

namespace App\Http\Requests\ServicePost;

use App\Enums\ServicePostMoveDirection;
use App\Models\ServicePost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveServicePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $servicePost = $this->route('servicePost');

        return $servicePost instanceof ServicePost && ($this->user()?->can('update', $servicePost) ?? false);
    }

    /**
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(ServicePostMoveDirection::class)],
        ];
    }
}
