@extends('layouts.app')

@section('title', 'Pengaturan Event')
@section('page-title', 'Pengaturan Event')
@section('page-subtitle', 'Format nomor serta kapasitas donor untuk event ini')

@section('content')
    @php($settingsAreEditable = $event->status->value === 'draft')

    <div
        class="mx-auto max-w-4xl"
        x-data="{ resetOpen: false, resetConfirmation: '' }"
        @sehat:operation-completed.window="if ($event.detail.form === $refs.resetQueueForm) { resetOpen = false; resetConfirmation = ''; }"
    >
        <div class="mb-6 flex items-center gap-3">
            <a href="{{ route('events.show', $event) }}" class="btn btn-ghost btn-sm btn-square" aria-label="Kembali ke detail event">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ $event->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $settingsAreEditable ? 'Atur nomor dan kapasitas sebelum event diaktifkan.' : 'Konfigurasi event aktif ditampilkan sebagai referensi dan tidak dapat diubah.' }}
                </p>
            </div>
        </div>

        <section class="mb-6 grid gap-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3" aria-label="Konteks event">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Nama event</p>
                <p class="mt-1 font-extrabold text-slate-900">{{ $event->name }}</p>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Tanggal event</p>
                <p class="mt-1 font-semibold text-slate-700">{{ $event->starts_at->translatedFormat('d M Y, H:i') }}</p>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Status event</p>
                <span class="mt-1 inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-extrabold text-emerald-700">{{ $event->status->label() }}</span>
            </div>
        </section>

        <form
            method="POST"
            action="{{ route('events.settings.update', $event) }}"
            class="space-y-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
            x-data="{
                donorMode: @js(old('donor_number_mode', $event->settings->donor_number_mode->value)),
                maleCapacity: Number(@js(old('donation_capacity_male', $event->settings->donation_capacity_male))),
                femaleCapacity: Number(@js(old('donation_capacity_female', $event->settings->donation_capacity_female))),
                get totalCapacity() { return this.maleCapacity + this.femaleCapacity; },
            }"
        >
            @csrf
            @method('PATCH')

            <fieldset @disabled(! $settingsAreEditable) class="contents">
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 text-sm leading-6 text-emerald-800">
                    @if ($settingsAreEditable)
                        Nomor registrasi laki-laki dan perempuan memiliki urutan masing-masing. Nomor donor diterbitkan saat peserta masuk proses donor.
                    @else
                        Event sudah aktif. Nomor antrean dan kapasitas dikunci agar proses operasional yang sedang berjalan tetap konsisten.
                    @endif
                </div>

            <section>
                <h2 class="text-base font-extrabold text-slate-900">Nomor registrasi</h2>
                <p class="mt-1 text-sm text-slate-500">Urutan registrasi dipisahkan per gender dan event: L001, L002 serta P001, P002.</p>
                <input type="hidden" name="registration_number_format" value="{{ \App\Enums\RegistrationNumberFormat::GenderPrefix->value }}">

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <label class="form-control">
                        <span class="label-text mb-2 font-semibold text-slate-700">Prefix laki-laki</span>
                        <input type="text" maxlength="10" name="registration_male_prefix" value="{{ old('registration_male_prefix', $event->settings->registration_male_prefix) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase">
                        @error('registration_male_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="form-control">
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
                <h2 class="text-base font-extrabold text-slate-900">Nomor proses donor</h2>
                <p class="mt-1 text-sm text-slate-500">Nomor pada Cek Kesehatan, Donor, Dashboard, dan TV selalu mengikuti nomor registrasi peserta. Pengaturan di bawah dipertahankan hanya agar tiket historis lama tetap dapat dibaca.</p>

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

            <section class="border-t border-slate-100 pt-7">
                <h2 class="text-base font-extrabold text-slate-900">Kapasitas donor paralel</h2>
                <p class="mt-1 text-sm text-slate-500">Kapasitas laki-laki dan perempuan dihitung secara independen saat donor berlangsung.</p>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <label class="form-control">
                        <span class="label-text mb-2 font-semibold text-slate-700">Jumlah bed laki-laki</span>
                        <input type="number" min="1" max="50" name="donation_capacity_male" x-model.number="maleCapacity" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white">
                        <span class="label-text-alt mt-2 text-slate-500">Contoh: L001, L002 memakai kapasitas ini.</span>
                        @error('donation_capacity_male')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="form-control">
                        <span class="label-text mb-2 font-semibold text-slate-700">Jumlah bed perempuan</span>
                        <input type="number" min="1" max="50" name="donation_capacity_female" x-model.number="femaleCapacity" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white">
                        <span class="label-text-alt mt-2 text-slate-500">Contoh: P001, P002 memakai kapasitas ini.</span>
                        @error('donation_capacity_female')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
                    </label>
                </div>
                <p class="mt-4 rounded-2xl bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">Total kapasitas event: <span x-text="totalCapacity"></span> bed</p>
            </section>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
                    <a href="{{ route('events.show', $event) }}" class="btn btn-ghost">Kembali</a>
                    @if ($settingsAreEditable)
                        <button type="submit" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">Simpan pengaturan</button>
                    @endif
                </div>
            </fieldset>
        </form>

        @can('resetQueue', $event)
            <section class="mt-8 rounded-3xl border border-rose-200 bg-rose-50/70 p-6 shadow-sm sm:p-8" aria-labelledby="queue-reset-heading">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-rose-700">Danger Zone</p>
                        <h2 id="queue-reset-heading" class="mt-2 text-lg font-extrabold text-rose-950">Reset Antrean Event</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-rose-900">
                            Menghapus seluruh data operasional event ini agar registrasi dan antrean dapat dimulai kembali dari nomor pertama. Data master peserta, event, pos, layanan, konfigurasi, kapasitas, serta jejak audit tidak dihapus.
                        </p>
                    </div>
                    <button type="button" class="btn border-0 bg-rose-600 text-white shadow-lg shadow-rose-200 hover:bg-rose-700" @click="resetOpen = true">
                        Reset Antrean Event
                    </button>
                </div>

                @if ($queueResetSummary->isEmpty())
                    <p class="mt-5 rounded-2xl border border-rose-100 bg-white/80 px-4 py-3 text-sm font-semibold text-rose-800">Antrean event saat ini sudah kosong.</p>
                @else
                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div class="rounded-2xl border border-rose-100 bg-white/80 p-4"><dt class="text-rose-700">Registrasi peserta</dt><dd class="mt-1 text-xl font-extrabold text-rose-950">{{ number_format($queueResetSummary->eventParticipants) }}</dd></div>
                        <div class="rounded-2xl border border-rose-100 bg-white/80 p-4"><dt class="text-rose-700">Tiket antrean</dt><dd class="mt-1 text-xl font-extrabold text-rose-950">{{ number_format($queueResetSummary->queueTickets) }}</dd></div>
                        <div class="rounded-2xl border border-rose-100 bg-white/80 p-4"><dt class="text-rose-700">Riwayat status</dt><dd class="mt-1 text-xl font-extrabold text-rose-950">{{ number_format($queueResetSummary->statusHistories) }}</dd></div>
                        <div class="rounded-2xl border border-rose-100 bg-white/80 p-4"><dt class="text-rose-700">Layanan peserta</dt><dd class="mt-1 text-xl font-extrabold text-rose-950">{{ number_format($queueResetSummary->participantServices) }}</dd></div>
                        <div class="rounded-2xl border border-rose-100 bg-white/80 p-4"><dt class="text-rose-700">Hasil proses lama</dt><dd class="mt-1 text-xl font-extrabold text-rose-950">{{ number_format($queueResetSummary->healthAssessments + $queueResetSummary->donorScreenings + $queueResetSummary->servicePostSubmissions) }}</dd></div>
                    </dl>
                @endif

                @if ($queueResetSummary->activeDonors > 0)
                    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold leading-6 text-amber-900" role="alert">
                        Peringatan: ada {{ $queueResetSummary->activeDonors }} peserta yang sedang donor. Reset tetap dapat dilakukan, tetapi seluruh posisi operasional aktif akan dihapus.
                    </div>
                @endif
            </section>

            <div
                x-cloak
                x-show="resetOpen"
                x-transition.opacity
                class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="queue-reset-modal-title"
                @keydown.escape.window="resetOpen = false; resetConfirmation = ''"
            >
                <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl sm:p-8" @click.outside="resetOpen = false; resetConfirmation = ''">
                    <div class="flex items-start gap-4">
                        <div class="grid size-11 shrink-0 place-items-center rounded-2xl bg-rose-100 text-rose-700" aria-hidden="true">!</div>
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-rose-600">Tindakan destruktif</p>
                            <h2 id="queue-reset-modal-title" class="mt-1 text-xl font-extrabold text-slate-950">Reset antrean {{ $event->name }}?</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">Tindakan ini menghapus data operasional yang tercantum di atas dan tidak dapat dibatalkan. Jejak audit akan tetap disimpan.</p>
                        </div>
                    </div>

                    <form
                        x-ref="resetQueueForm"
                        method="POST"
                        action="{{ route('events.queue-reset.store', $event) }}"
                        class="mt-6 space-y-5"
                        data-realtime-submit
                        data-operation-kind="queue-reset"
                    >
                        @csrf
                        <label class="form-control">
                            <span class="label-text mb-2 font-bold text-slate-800">Ketik <span class="font-mono text-rose-700">RESET</span> untuk melanjutkan</span>
                            <input x-model="resetConfirmation" type="text" name="confirmation" autocomplete="off" class="input input-bordered w-full rounded-xl border-slate-300 font-mono uppercase focus:border-rose-500 focus:outline-rose-500" required>
                        </label>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-ghost" @click="resetOpen = false; resetConfirmation = ''">Batal</button>
                            <button type="submit" class="btn border-0 bg-rose-600 text-white hover:bg-rose-700 disabled:border-0 disabled:bg-rose-200" :disabled="resetConfirmation !== 'RESET'">
                                Ya, Reset Antrean
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    </div>
@endsection
