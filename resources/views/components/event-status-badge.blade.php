@props(['status'])

@php
    $value = $status instanceof \App\Enums\EventStatus ? $status->value : $status;
    $label = $status instanceof \App\Enums\EventStatus ? $status->label() : ucfirst((string) $status);
    $classes = match ($value) {
        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'completed' => 'border-sky-200 bg-sky-50 text-sky-700',
        'cancelled' => 'border-rose-200 bg-rose-50 text-rose-700',
        default => 'border-amber-200 bg-amber-50 text-amber-700',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold {$classes}"]) }}>
    <span class="size-1.5 rounded-full bg-current"></span>
    {{ $label }}
</span>
