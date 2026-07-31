<?php

namespace App\Http\Requests\Donation;

use App\Models\QueueTicket;
use Illuminate\Foundation\Http\FormRequest;

class DonationQueueActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageDonation', QueueTicket::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
