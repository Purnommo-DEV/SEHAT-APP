@extends('layouts.app')

@section('title', 'Master Peserta')
@section('page-title', 'Master Peserta')
@section('page-subtitle', 'Identitas peserta yang dapat digunakan lintas event')

@section('content')
    <div x-data="participantIndex(@js($participantsJson), @js($query), @js(route('participants.data')), @js(route('participants.autocomplete')))" class="space-y-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-semibold text-emerald-700">MASTER IDENTITAS</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Peserta</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Cari berdasarkan nama, nomor HP, atau NIK. Identitas ini dapat dipakai kembali pada event berikutnya tanpa memasukkan data dua kali.</p>
            </div>
            <a href="{{ route('participants.create') }}" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                Tambah peserta
            </a>
        </div>

        <div class="relative rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <label class="form-control">
                <span class="label-text mb-2 font-semibold text-slate-700">Cari peserta</span>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path stroke-linecap="round" d="m16 16 4 4"/></svg>
                    <input x-model="query" @input.debounce.300ms="refresh(); autocomplete()" @keydown.escape="suggestions = []" type="search" class="input input-bordered w-full rounded-2xl border-slate-300 bg-white pl-12 focus:border-emerald-500 focus:outline-none" placeholder="Ketik nama, nomor HP, atau NIK..." autocomplete="off">
                </div>
            </label>

            <div x-show="suggestions.length > 0" x-cloak class="absolute left-5 right-5 z-20 mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/70 sm:left-6 sm:right-6">
                <template x-for="participant in suggestions" :key="participant.id">
                    <button type="button" @click="selectSuggestion(participant)" class="flex w-full items-center justify-between gap-4 border-l-4 px-4 py-3 text-left transition" :class="$store.ui.genderCardClass(participant.gender)">
                        <span class="min-w-0"><span class="block truncate font-semibold text-slate-800" x-text="participant.name"></span><span class="mt-0.5 block truncate text-xs text-slate-500" x-text="[participant.phone, participant.nik].filter(Boolean).join(' · ') || 'Kontak belum tersedia'"></span></span>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.gender)">
                            <span x-text="$store.ui.genderIcon(participant.gender)"></span>
                            <span x-text="participant.gender_label"></span>
                        </span>
                    </button>
                </template>
            </div>
            <p class="mt-3 text-xs text-slate-500">Autocomplete muncul mulai dua karakter. Daftar menampilkan maksimal 100 hasil; persempit kata kunci untuk hasil yang lebih spesifik.</p>
        </div>

        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 class="font-bold text-slate-900">Hasil peserta</h2>
                    <p class="mt-1 text-sm text-slate-500"><span x-text="participants.length"></span> hasil ditemukan</p>
                </div>
                <span class="flex items-center gap-2 text-xs font-semibold text-slate-500"><span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span><span x-text="isRefreshing ? 'Mencari' : 'Pencarian realtime'"></span></span>
            </div>

            <div x-show="participants.length === 0" class="px-6 py-16 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path stroke-linecap="round" d="m16 16 4 4"/></svg></div>
                <p class="mt-4 font-bold text-slate-800">Peserta tidak ditemukan</p>
                <p class="mt-1 text-sm text-slate-500">Coba kata kunci lain atau tambahkan peserta baru.</p>
            </div>

            <div x-show="participants.length > 0" x-cloak class="overflow-x-auto">
                <table class="table min-w-[850px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Peserta</th><th>Kontak</th><th>Identitas</th><th>Jenis kelamin</th><th class="text-right">Aksi</th></tr></thead>
                    <tbody>
                        <template x-for="participant in participants" :key="participant.id">
                            <tr :class="$store.ui.genderCardClass(participant.gender)">
                                <td><p class="font-bold text-slate-900" x-text="participant.name"></p><p x-show="participant.address" x-cloak class="mt-1 max-w-xs truncate text-xs text-slate-500" x-text="participant.address"></p></td>
                                <td class="font-medium text-slate-700" x-text="participant.phone || '—'"></td>
                                <td><span class="font-mono text-xs text-slate-600" x-text="participant.nik || '—'"></span></td>
                                <td><span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.gender)"><span x-text="$store.ui.genderIcon(participant.gender)"></span> <span x-text="participant.gender_label"></span></span></td>
                                <td><div class="flex justify-end gap-2"><a :href="participant.urls.edit" class="btn btn-ghost btn-sm">Ubah</a><form :action="participant.urls.destroy" method="POST" :data-confirm-title="'Hapus ' + participant.name + '?'" data-confirm-message="Data peserta akan dihapus dari master identitas." data-confirm-variant="warning">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button></form></div></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        <p x-show="lastError" x-cloak class="text-center text-sm font-semibold text-rose-600" x-text="lastError"></p>
    </div>
@endsection
