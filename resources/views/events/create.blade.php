@extends('layouts.app')

@section('title', 'Buat Event')
@section('page-title', 'Buat Event')
@section('page-subtitle', 'Siapkan identitas dan jadwal kegiatan baru')

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6 flex items-center gap-3">
            <a href="{{ route('events.index') }}" class="btn btn-ghost btn-sm btn-square" aria-label="Kembali ke daftar event">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">Event baru</h1>
                <p class="mt-1 text-sm text-slate-500">Event dibuat sebagai draf dan belum dapat menerima alur operasional.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('events.store') }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @csrf
            <x-event-form />

            <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
                <a href="{{ route('events.index') }}" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">Simpan event</button>
            </div>
        </form>
    </div>
@endsection
