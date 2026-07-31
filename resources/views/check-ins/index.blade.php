@extends('layouts.app')

@section('title', 'Registrasi Ulang')
@section('page-title', 'Registrasi Ulang')
@section('page-subtitle', 'Hadirkan peserta, pilih layanan, dan terbitkan nomor registrasi')

@section('content')
    @php
        $defaultService = collect(\App\Enums\ParticipantServiceType::cases())
            ->first(fn (\App\Enums\ParticipantServiceType $serviceType): bool => $workflow->serviceAvailable($serviceType));
        $initialServices = old('services', $defaultService ? [$defaultService->value] : []);
    @endphp

    <div
        x-data="checkInDesk(
            @js($ticketsJson),
            @js(route('events.check-ins.data', $event)),
            @js(route('participants.autocomplete')),
            @js(route('events.check-ins.participants.store', $event)),
            {{ $event->id }},
            @js($workflowJson),
            @js($initialServices)
        )"
        @open-quick-participant.window="openQuickParticipant()"
        class="mx-auto max-w-7xl space-y-6"
    >
        <div class="rounded-3xl bg-gradient-to-br from-emerald-700 to-emerald-500 p-6 text-white shadow-xl shadow-emerald-200 sm:p-8">
            <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-100">Event aktif · {{ $event->code }}</p>
                    <h1 class="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $event->name }}</h1>
                    <p class="mt-2 text-sm text-emerald-50">{{ $event->location }} · {{ $event->starts_at->translatedFormat('d F Y, H:i') }}</p>
                </div>
                <div class="rounded-2xl bg-white/15 px-5 py-4 backdrop-blur">
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-100">Registrasi diterbitkan</p>
                    <p class="mt-1 text-3xl font-black" x-text="tickets.length"></p>
                    <p class="text-xs text-emerald-50">20 registrasi terakhir</p>
                </div>
            </div>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <section
            x-show="workflowMessages.length > 0"
            x-cloak
            role="alert"
            class="rounded-3xl border border-amber-200 bg-amber-50 p-5 sm:p-6"
        >
                <h2 class="font-extrabold text-amber-950" x-text="workflowHeading">
                    {{ $workflow->hasAvailableService() ? 'Sebagian layanan belum tersedia' : 'Check-In belum dapat digunakan' }}
                </h2>
                <p
                    x-show="eventActive && hasAvailableService"
                    class="mt-1 text-sm leading-6 text-amber-900"
                >
                    Registrasi tetap dapat dilakukan untuk layanan yang sudah siap.
                </p>
                <ul class="mt-2 list-inside list-disc text-sm leading-6 text-amber-900">
                    <template x-for="message in workflowMessages" :key="message">
                        <li x-text="message"></li>
                    </template>
                </ul>
                @can('service-posts.manage')
                    <a href="{{ route('events.show', $event) }}#jalur-pelayanan" class="btn btn-sm mt-4 border-0 bg-amber-700 text-white hover:bg-amber-800">Atur Jalur Pelayanan</a>
                @endcan
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,0.8fr)]">
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex items-start gap-4">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 20v-2a4.5 4.5 0 0 1 4.5-4.5h2A4.5 4.5 0 0 1 14.5 18v2M17 8v6m-3-3h6"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-xl font-extrabold text-slate-900">Cari peserta</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Pilih identitas yang sudah terdaftar. Sistem akan mengunci event dan menghasilkan nomor secara atomik.</p>
                    </div>
                    @can(\App\Enums\PermissionName::ManageCheckIn->value)
                        <button type="button" @click.stop="openQuickParticipant()" class="btn btn-sm btn-outline shrink-0 border-emerald-200 text-emerald-700 hover:border-emerald-600 hover:bg-emerald-600 hover:text-white">+ Peserta baru</button>
                    @endcan
                </div>

                <form method="POST" action="{{ route('events.check-ins.store', $event) }}" class="mt-7 space-y-5" data-realtime-submit @submit="submitting = true">
                    @csrf
                    <input
                        type="hidden"
                        name="participant_id"
                        :value="selected?.id ?? ''"
                        :disabled="! eventActive || ! hasAvailableService"
                    >

                    <div class="relative">
                        <label for="participant-search" class="mb-2 block text-sm font-bold text-slate-700">Nama, nomor HP, atau NIK</label>
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path stroke-linecap="round" d="m16 16 4 4"/></svg>
                            <input
                                id="participant-search"
                                x-model="query"
                                @input.debounce.300ms="search()"
                                @focus="search()"
                                @keydown.escape="suggestions = []; searchCompleted = false"
                                @keydown.arrow-down.prevent="moveSuggestion(1)"
                                @keydown.arrow-up.prevent="moveSuggestion(-1)"
                                @keydown.enter.prevent="chooseActiveSuggestion()"
                                type="search"
                                class="input input-bordered h-13 w-full rounded-2xl border-slate-300 bg-white pl-12 pr-12 text-base focus:border-emerald-500 focus:outline-none"
                                placeholder="Ketik minimal 2 karakter..."
                                autocomplete="off"
                            >
                            <span x-show="searchLoading" x-cloak class="loading loading-spinner loading-sm absolute right-4 top-1/2 -translate-y-1/2 text-emerald-600" aria-label="Mencari peserta"></span>
                        </div>

                        <div
                            x-show="query.trim().length >= 2 && ! selected && (searchLoading || searchCompleted)"
                            x-cloak
                            @click.outside="suggestions = []; searchCompleted = false"
                            class="absolute z-20 mt-2 max-h-80 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-200/70"
                        >
                            <template x-for="participant in suggestions" :key="participant.id">
                                <button
                                    type="button"
                                    @click="select(participant)"
                                    class="flex w-full items-center justify-between gap-4 rounded-xl border px-4 py-3 text-left transition"
                                    :class="[
                                        $store.ui.genderCardClass(participant.gender),
                                        activeSuggestionIndex === suggestions.indexOf(participant) ? 'ring-2 ring-emerald-400' : '',
                                    ]"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate font-bold text-slate-800" x-text="participant.name"></span>
                                        <span class="mt-1 block truncate text-xs text-slate-500" x-text="[participant.phone, participant.nik].filter(Boolean).join(' · ')"></span>
                                    </span>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.gender)">
                                        <span x-text="$store.ui.genderIcon(participant.gender)"></span>
                                        <span x-text="participant.gender_label"></span>
                                    </span>
                                </button>
                            </template>
                            <div x-show="! searchLoading && searchCompleted && suggestions.length === 0" class="p-3 text-center">
                                <p class="text-sm font-semibold text-slate-600">Peserta tidak ditemukan.</p>
                                @can(\App\Enums\PermissionName::ManageCheckIn->value)
                                    <button type="button" @click.stop="$dispatch('open-quick-participant')" class="btn btn-sm mt-3 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">+ Tambah Peserta Baru</button>
                                @endcan
                            </div>
                        </div>
                    </div>

                    <div x-show="selected" x-cloak class="rounded-2xl border p-4" :class="$store.ui.genderCardClass(selected?.gender)">
                        <div class="flex items-center gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl font-black shadow-lg" :class="$store.ui.genderNumberClass(selected?.gender)" x-text="selected?.name?.charAt(0)?.toUpperCase()"></span>
                            <div class="min-w-0">
                                <p class="truncate font-extrabold text-slate-950" x-text="selected?.name"></p>
                                <p class="mt-0.5 truncate text-xs font-medium text-slate-600" x-text="[selected?.phone, selected?.nik].filter(Boolean).join(' · ') || 'Nomor HP belum tersedia'"></p>
                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(selected?.gender)">
                                    <span class="mr-1" x-text="$store.ui.genderIcon(selected?.gender)"></span>
                                    <span x-text="selected?.gender_label"></span>
                                </span>
                            </div>
                            <button type="button" @click="clear()" class="btn btn-ghost btn-sm ml-auto text-slate-700">Ganti</button>
                        </div>
                    </div>

                    <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <legend class="px-1 text-sm font-extrabold text-slate-800">Layanan yang diinginkan</legend>
                        <p class="mt-1 text-sm text-slate-500">Pilih satu atau keduanya. Sistem hanya menerbitkan nomor donor setelah peserta dinyatakan layak.</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach (\App\Enums\ParticipantServiceType::cases() as $serviceType)
                                <label
                                    class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 transition"
                                    :class="eventActive && serviceAvailable(@js($serviceType->value))
                                        ? 'cursor-pointer hover:border-emerald-300'
                                        : 'cursor-not-allowed opacity-55'"
                                >
                                    <input
                                        type="checkbox"
                                        name="services[]"
                                        value="{{ $serviceType->value }}"
                                        x-model="selectedServices"
                                        :disabled="! eventActive || ! serviceAvailable(@js($serviceType->value))"
                                        class="checkbox checkbox-success"
                                    >
                                    <span>
                                        <span class="block font-extrabold text-slate-900">{{ $serviceType->label() }}</span>
                                        <span class="mt-0.5 block text-xs text-slate-500">{{ $serviceType->description() }}</span>
                                        <span
                                            x-show="! serviceAvailable(@js($serviceType->value))"
                                            x-cloak
                                            class="mt-1 block text-xs font-bold text-amber-700"
                                        >
                                            Belum tersedia pada event ini.
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('services')<p class="mt-3 text-sm font-semibold text-rose-600">{{ $message }}</p>@enderror
                    </fieldset>

                    <button
                        id="check-in-submit"
                        type="submit"
                        :disabled="! selected || submitting || ! eventActive || ! hasAvailableService || selectedServices.length === 0"
                        class="btn sticky bottom-3 z-10 h-13 w-full border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700 disabled:bg-slate-300 sm:static"
                    >
                        <span x-show="submitting" class="loading loading-spinner loading-sm"></span>
                        <svg x-show="! submitting" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-4-4 4 4-4 4"/></svg>
                        Hadirkan dan terbitkan nomor registrasi
                    </button>
                </form>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="font-extrabold text-slate-900">Check-in terbaru</h2>
                    <p class="mt-1 text-xs text-slate-500">Diperbarui langsung melalui Reverb</p>
                    </div>
                    <span class="size-2.5 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span>
                </div>

                <div x-show="tickets.length === 0" class="px-6 py-14 text-center">
                    <p class="font-bold text-slate-700">Belum ada peserta check-in</p>
                    <p class="mt-1 text-sm text-slate-500">Nomor registrasi pertama akan tampil di sini.</p>
                </div>

                <ol x-show="tickets.length > 0" x-cloak class="max-h-[35rem] divide-y divide-slate-100 overflow-y-auto">
                    <template x-for="ticket in tickets" :key="ticket.id">
                        <li class="border-l-4" :class="$store.ui.genderCardClass(ticket.participant.gender)">
                            <a :href="ticket.urls.show" class="flex items-center gap-4 px-4 py-4 transition hover:brightness-[0.98] sm:px-5">
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl font-mono text-xl font-black shadow-lg" :class="$store.ui.genderNumberClass(ticket.participant.gender)" x-text="ticket.registration_number"></span>
                                <span class="min-w-0">
                                    <span class="block truncate font-bold text-slate-900" x-text="ticket.participant.name"></span>
                                    <span class="mt-1 block truncate text-xs text-slate-500" x-text="ticket.services.map(service => service.label).join(' + ')"></span>
                                    <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-[0.7rem] font-bold" :class="$store.ui.genderBadgeClass(ticket.participant.gender)">
                                        <span class="mr-1" x-text="$store.ui.genderIcon(ticket.participant.gender)"></span>
                                        <span x-text="ticket.participant.gender_label"></span>
                                    </span>
                                </span>
                                <svg class="ml-auto size-5 shrink-0 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                            </a>
                        </li>
                    </template>
                </ol>
            </section>
        </div>

        <div
            x-show="quickOpen"
            x-cloak
            class="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-4"
            @keydown.escape.window="closeQuickParticipant()"
            @click.self="closeQuickParticipant()"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="quick-participant-title"
                class="max-h-[92dvh] w-full overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-7"
            >
                <div class="mx-auto mb-4 h-1.5 w-12 rounded-full bg-slate-200 sm:hidden"></div>
                <div class="flex items-start gap-4">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path stroke-linecap="round" d="M3.5 20v-2A4.5 4.5 0 0 1 8 13.5h2A4.5 4.5 0 0 1 14.5 18v2M18 8v6m-3-3h6"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 id="quick-participant-title" class="text-xl font-extrabold text-slate-900">Tambah peserta cepat</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Cukup nama dan nomor HP bila tersedia.</p>
                    </div>
                    <button type="button" @click="closeQuickParticipant()" class="btn btn-ghost btn-sm btn-square" aria-label="Tutup">✕</button>
                </div>

                <form @submit.prevent="createQuickParticipant()" class="mt-6 space-y-5">
                    <label class="form-control">
                        <span class="label-text mb-2 font-bold text-slate-700">Nama lengkap <span class="text-rose-600">*</span></span>
                        <input id="quick-participant-name" x-model="quickParticipant.name" required maxlength="255" class="input input-bordered h-13 w-full rounded-xl bg-white text-base focus:border-emerald-500 focus:outline-none" autocomplete="name" placeholder="Nama peserta">
                        <span x-show="quickErrors.name" class="mt-2 text-xs font-semibold text-rose-600" x-text="quickErrors.name?.[0]"></span>
                    </label>

                    <label class="form-control">
                        <span class="label-text mb-2 font-bold text-slate-700">Nomor HP <span class="font-normal text-slate-400">(opsional)</span></span>
                        <input x-model="quickParticipant.phone" type="tel" inputmode="tel" maxlength="20" class="input input-bordered h-13 w-full rounded-xl bg-white text-base focus:border-emerald-500 focus:outline-none" autocomplete="tel" placeholder="081234567890">
                        <span x-show="quickErrors.phone" class="mt-2 text-xs font-semibold text-rose-600" x-text="quickErrors.phone?.[0]"></span>
                    </label>

                    <fieldset>
                        <legend class="text-sm font-bold text-slate-700">Jenis kelamin untuk nomor antrean</legend>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <label class="cursor-pointer rounded-2xl border p-4 text-center transition" :class="quickParticipant.gender === 'male' ? 'border-indigo-400 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200'">
                                <input type="radio" x-model="quickParticipant.gender" value="male" class="sr-only">
                                <span class="block text-2xl">👨</span>
                                <span class="mt-1 block text-sm font-extrabold text-indigo-900">Laki-laki</span>
                            </label>
                            <label class="cursor-pointer rounded-2xl border p-4 text-center transition" :class="quickParticipant.gender === 'female' ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-100' : 'border-slate-200'">
                                <input type="radio" x-model="quickParticipant.gender" value="female" class="sr-only">
                                <span class="block text-2xl">👩</span>
                                <span class="mt-1 block text-sm font-extrabold text-rose-900">Perempuan</span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-3 pt-2">
                        <button type="button" @click="closeQuickParticipant()" class="btn btn-ghost">Batal</button>
                        <button type="submit" :disabled="quickSubmitting || ! quickParticipant.name.trim()" class="btn h-12 border-0 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300">
                            <span x-show="quickSubmitting" class="loading loading-spinner loading-sm"></span>
                            <span x-text="quickSubmitting ? 'Menyimpan...' : 'Simpan dan pilih peserta'"></span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>
@endsection
