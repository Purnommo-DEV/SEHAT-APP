<?php

namespace App\Http\Requests\ServicePost;

use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\ServicePost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServicePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && ($this->user()?->can('create', [ServicePost::class, $event]) ?? false);
    }

    /**
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/', Rule::unique('service_posts', 'code')->where('event_id', $event->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // `type` is accepted only as a migration/legacy input. New UI
            // submits the behavior directly, while older clients can still
            // create a post without being forced to know the new contract.
            'type' => ['sometimes', Rule::enum(ServicePostType::class)],
            'behavior' => ['required', Rule::enum(ServicePostBehavior::class)],
            'queue_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9-]*$/'],
            'queue_number_digits' => ['required', 'integer', 'between:1,6'],
            'is_active' => ['sometimes', 'boolean'],
            'operator_ids' => ['sometimes', 'array'],
            'operator_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode pos hanya boleh memuat huruf kecil, angka, dan tanda hubung.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $behavior = $this->input('behavior');
        $legacyType = ServicePostType::tryFrom((string) $this->input('type'));

        if ($behavior === null && $legacyType !== null) {
            $behavior = match ($legacyType) {
                ServicePostType::Health => ServicePostBehavior::HealthForm->value,
                ServicePostType::Screening => ServicePostBehavior::ScreeningForm->value,
                ServicePostType::Donation => ServicePostBehavior::DonationForm->value,
                ServicePostType::Registration,
                ServicePostType::Completion,
                ServicePostType::Custom => ServicePostBehavior::ConfirmationOnly->value,
            };
        }
        $behaviorEnum = is_string($behavior) ? ServicePostBehavior::tryFrom($behavior) : null;
        $queuePrefix = trim((string) $this->input('queue_prefix'));

        $this->merge([
            'code' => Str::lower(trim((string) $this->input('code'))),
            'behavior' => $behavior,
            'queue_prefix' => Str::upper($queuePrefix !== ''
                ? $queuePrefix
                : (string) $behaviorEnum?->defaultQueuePrefix()),
            'queue_number_digits' => $this->input('queue_number_digits', 3),
        ]);
    }
}
