@extends('layouts.app')

@section('title', 'Master Event')
@section('page-title', 'Master Event')
@section('page-subtitle', 'Kelola kegiatan dan pilih satu event operasional aktif')

@section('content')
    <div x-data="eventIndex(@js($eventsJson), @js(route('events.data')))" class="space-y-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-semibold text-emerald-700">PUSAT OPERASIONAL</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Daftar event</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Satu event aktif menjadi konteks bagi seluruh registrasi, antrean, pemeriksaan, dan pelaporan berikutnya.</p>
            </div>
            <a href="{{ route('events.create') }}" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
                </svg>
                Buat event
            </a>
        </div>

        <div class="rounded-3xl border border-emerald-100 bg-gradient-to-br from-emerald-600 to-teal-600 p-6 text-white shadow-xl shadow-emerald-100 sm:p-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-bold tracking-[0.18em] text-emerald-100">EVENT AKTIF</p>
                    <template x-if="events.some((event) => event.is_active)">
                        <div>
                            <p class="mt-2 text-2xl font-extrabold sm:text-3xl" x-text="events.find((event) => event.is_active).name"></p>
                            <p class="mt-2 text-sm text-emerald-100"><span x-text="events.find((event) => event.is_active).code"></span> · <span x-text="formatDate(events.find((event) => event.is_active).starts_at)"></span></p>
                        </div>
                    </template>
                    <template x-if="! events.some((event) => event.is_active)">
                        <div>
                            <p class="mt-2 text-2xl font-extrabold sm:text-3xl">Belum ada event aktif</p>
                            <p class="mt-2 text-sm text-emerald-100">Aktifkan satu event draf sebelum memasuki alur operasional hari H.</p>
                        </div>
                    </template>
                </div>
                <div class="rounded-2xl border border-white/20 bg-white/10 px-4 py-3 backdrop-blur-sm">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-300' : 'bg-emerald-200'"></span>
                        <span x-text="isRefreshing ? 'Memperbarui data realtime' : 'Reverb siap menerima perubahan'"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 class="font-bold text-slate-900">Semua event</h2>
                    <p class="mt-1 text-sm text-slate-500"><span x-text="events.length"></span> event tercatat</p>
                </div>
                <span x-show="lastError" x-cloak class="text-xs font-semibold text-rose-600" x-text="lastError"></span>
            </div>

            <div x-show="events.length === 0" class="px-6 py-16 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 3v3m8-3v3M4.5 9.5h15M6.5 5h11A1.5 1.5 0 0 1 19 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 17.5v-11A1.5 1.5 0 0 1 6.5 5Z"/>
                    </svg>
                </div>
                <p class="mt-4 font-bold text-slate-800">Belum ada event</p>
                <p class="mt-1 text-sm text-slate-500">Buat event pertama untuk menyiapkan alur pelayanan.</p>
            </div>

            <div x-show="events.length > 0" x-cloak class="overflow-x-auto">
                <table class="table min-w-[900px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th>Event</th>
                            <th>Waktu & lokasi</th>
                            <th>Status</th>
                            <th>Konfigurasi antrean</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="event in events" :key="event.id">
                            <tr class="hover:bg-slate-50/80">
                                <td>
                                    <a :href="event.urls.show" class="font-bold text-slate-900 hover:text-emerald-700" x-text="event.name"></a>
                                    <p class="mt-1 font-mono text-xs font-semibold text-slate-500" x-text="event.code"></p>
                                </td>
                                <td>
                                    <p class="font-semibold text-slate-700" x-text="formatDate(event.starts_at)"></p>
                                    <p class="mt-1 text-xs text-slate-500" x-text="event.location || 'Lokasi belum diatur'"></p>
                                </td>
                                <td>
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold" :class="{
                                        'border-emerald-200 bg-emerald-50 text-emerald-700': event.status === 'active',
                                        'border-sky-200 bg-sky-50 text-sky-700': event.status === 'completed',
                                        'border-rose-200 bg-rose-50 text-rose-700': event.status === 'cancelled',
                                        'border-amber-200 bg-amber-50 text-amber-700': event.status === 'draft',
                                    }">
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        <span x-text="event.status_label"></span>
                                    </span>
                                </td>
                                <td>
                                    <span class="rounded-lg bg-sky-50 px-2 py-1 font-mono text-xs font-bold text-sky-700" x-text="event.settings.registration_male_prefix + String(1).padStart(event.settings.registration_queue_digits, '0')"></span>
                                    <span class="ml-1 rounded-lg bg-rose-50 px-2 py-1 font-mono text-xs font-bold text-rose-700" x-text="event.settings.registration_female_prefix + String(1).padStart(event.settings.registration_queue_digits, '0')"></span>
                                    <p class="mt-2 text-xs font-semibold text-slate-500">Nomor donor mengikuti registrasi</p>
                                </td>
                                <td>
                                    <div class="flex justify-end gap-1.5">
                                        <a :href="event.urls.show" class="btn btn-ghost btn-sm" title="Lihat event">Detail</a>
                                        <template x-if="event.status === 'draft'">
                                            <a :href="event.urls.settings" class="btn btn-ghost btn-sm" title="Atur nomor donor">Nomor</a>
                                        </template>
                                        <template x-if="event.status === 'draft'">
                                            <form :action="event.urls.activate" method="POST" :data-confirm-title="'Aktifkan ' + event.name + '?'" data-confirm-message="Event aktif menjadi konteks operasional seluruh panitia.">
                                                @csrf
                                                <button type="submit" class="btn btn-sm border-0 bg-emerald-600 text-white hover:bg-emerald-700">Aktifkan</button>
                                            </form>
                                        </template>
                                        <template x-if="event.status === 'active'">
                                            <form :action="event.urls.complete" method="POST" :data-confirm-title="'Selesaikan ' + event.name + '?'" data-confirm-message="Event selesai tidak dapat kembali ke status aktif." data-confirm-variant="question">
                                                @csrf
                                                <button type="submit" class="btn btn-sm border-sky-200 bg-sky-50 text-sky-700 hover:border-sky-300 hover:bg-sky-100">Selesaikan</button>
                                            </form>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
