@extends('layouts.app')

@section('title', 'Tambah Pos Pelayanan')
@section('page-title', 'Tambah Pos Pelayanan')
@section('page-subtitle', 'Tambahkan tahap baru ke jalur layanan event')

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6 flex items-center gap-3">
            <a href="{{ route('events.show', $event) }}#jalur-pelayanan" class="btn btn-ghost btn-sm btn-square" aria-label="Kembali ke Jalur Pelayanan">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">Pos baru</h1>
                <p class="mt-1 text-sm text-slate-500">
                    @if ($activeRepair)
                        Lengkapi layanan SOP yang belum tersedia. Pos baru akan langsung diaktifkan tanpa mengubah histori peserta.
                    @else
                        Pos akan ditempatkan pada urutan terakhir dan dapat dipindahkan setelah disimpan.
                    @endif
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('events.service-posts.store', $event) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @csrf
            <x-service-post-form :operators="$operators" :active-repair="$activeRepair" :behaviors="$creatableBehaviors" />
            <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
                <a href="{{ route('events.show', $event) }}#jalur-pelayanan" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">{{ $activeRepair ? 'Simpan dan aktifkan pos' : 'Simpan pos' }}</button>
            </div>
        </form>
    </div>
@endsection
