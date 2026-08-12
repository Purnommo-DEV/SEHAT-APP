<?php

namespace App\Http\Requests\Operational;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDonationCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && ($this->user()?->can('updateDonationCapacity', $event) ?? false);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'donation_capacity_male' => ['required', 'integer', 'min:1', 'max:50'],
            'donation_capacity_female' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
