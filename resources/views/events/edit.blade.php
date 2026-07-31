@extends('layouts.app')

@section('title', 'Ubah Event')
@section('page-title', 'Ubah Event')
@section('page-subtitle', 'Perbarui identitas kegiatan yang masih berupa draf')

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-6 flex items-center gap-3">
            <a href="{{ route('events.show', $event) }}" class="btn btn-ghost btn-sm btn-square" aria-label="Kembali ke detail event">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">Ubah {{ $event->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">Status saat ini: <x-event-status-badge :status="$event->status" /></p>
            </div>
        </div>

        <form method="POST" action="{{ route('events.update', $event) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @csrf
            @method('PUT')
            <x-event-form :event="$event" />

            <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
                <a href="{{ route('events.show', $event) }}" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">Simpan perubahan</button>
            </div>
        </form>
    </div>
@endsection
