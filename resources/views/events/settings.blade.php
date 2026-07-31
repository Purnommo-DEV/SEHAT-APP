@extends('layouts.app')

@section('title', 'Pengaturan Event')
@section('page-title', 'Pengaturan Nomor')
@section('page-subtitle', 'Format nomor registrasi dan donor untuk event ini')

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6 flex items-center gap-3">
            <a href="{{ route('events.show', $event) }}" class="btn btn-ghost btn-sm btn-square" aria-label="Kembali ke detail event">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ $event->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">Pengaturan hanya dapat diubah selama event masih berstatus draf.</p>
            </div>
        </div>

        <form
            method="POST"
            action="{{ route('events.settings.update', $event) }}"
            class="space-y-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
            x-data="{
                registrationFormat: @js(old('registration_number_format', $event->settings->registration_number_format->value)),
                donorMode: @js(old('donor_number_mode', $event->settings->donor_number_mode->value))
            }"
        >
            @csrf
            @method('PATCH')

            <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 text-sm leading-6 text-emerald-800">
                Angka registrasi selalu memakai satu urutan global. Nomor donor baru diterbitkan setelah peserta dinyatakan layak.
            </div>

            <section>
                <h2 class="text-base font-extrabold text-slate-900">Nomor registrasi</h2>
                <p class="mt-1 text-sm text-slate-500">Contoh prefix gender dengan urutan global: L001, P002, L003.</p>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <label class="form-control sm:col-span-2">
                        <span class="label-text mb-2 font-semibold text-slate-700">Format prefix registrasi</span>
                        <select name="registration_number_format" x-model="registrationFormat" class="select select-bordered w-full rounded-xl border-slate-300 bg-white">
                            @foreach (\App\Enums\RegistrationNumberFormat::cases() as $format)
                                <option value="{{ $format->value }}">{{ $format->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="form-control" x-show="registrationFormat === 'uniform'" x-cloak>
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix registrasi</span>
                        <input type="text" maxlength="10" name="registration_queue_prefix" value="{{ old('registration_queue_prefix', $event->settings->registration_queue_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('registration_queue_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control" x-show="registrationFormat === 'gender_prefix'" x-cloak>
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix laki-laki</span>
                        <input type="text" maxlength="10" name="registration_male_prefix" value="{{ old('registration_male_prefix', $event->settings->registration_male_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('registration_male_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control" x-show="registrationFormat === 'gender_prefix'" x-cloak>
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix perempuan</span>
                        <input type="text" maxlength="10" name="registration_female_prefix" value="{{ old('registration_female_prefix', $event->settings->registration_female_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('registration_female_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control">
                        <span class="label-text mb-2 font-semibold text-slate-700">Jumlah digit registrasi</span>
                        <input type="number" min="1" max="6" name="registration_queue_digits" value="{{ old('registration_queue_digits', $event->settings->registration_queue_digits) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white">
                        @error('registration_queue_digits')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>

            <section class="border-t border-slate-100 pt-7">
                <h2 class="text-base font-extrabold text-slate-900">Nomor donor</h2>
                <p class="mt-1 text-sm text-slate-500">Gunakan antrean global atau pisahkan urutan laki-laki dan perempuan.</p>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <label class="form-control sm:col-span-2">
                        <span class="label-text mb-2 font-semibold text-slate-700">Mode nomor donor</span>
                        <select name="donor_number_mode" x-model="donorMode" class="select select-bordered w-full rounded-xl border-slate-300 bg-white">
                            @foreach (\App\Enums\DonorNumberMode::cases() as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="form-control" x-show="donorMode === 'global'" x-cloak>
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix donor global</span>
                        <input type="text" maxlength="10" name="donor_queue_prefix" value="{{ old('donor_queue_prefix', $event->settings->donor_queue_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('donor_queue_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control" x-show="donorMode === 'gender_separated'" x-cloak>
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix donor laki-laki</span>
                        <input type="text" maxlength="5" name="male_donor_queue_prefix" value="{{ old('male_donor_queue_prefix', $event->settings->male_donor_queue_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('male_donor_queue_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control" x-show="donorMode === 'gender_separated'" x-cloak>
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix donor perempuan</span>
                        <input type="text" maxlength="5" name="female_donor_queue_prefix" value="{{ old('female_donor_queue_prefix', $event->settings->female_donor_queue_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('female_donor_queue_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control">
                        <span class="label-text mb-2 font-semibold text-slate-700">Jumlah digit donor</span>
                        <input type="number" min="1" max="6" name="donor_queue_digits" value="{{ old('donor_queue_digits', $event->settings->donor_queue_digits) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white">
                        @error('donor_queue_digits')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>

            <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
                <a href="{{ route('events.show', $event) }}" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">Simpan pengaturan</button>
            </div>
        </form>
    </div>
@endsection
