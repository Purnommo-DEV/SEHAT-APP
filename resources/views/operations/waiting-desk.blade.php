@extends('layouts.operational')

@section('title', 'Screen Petugas Area Tunggu')
@section('page-title', 'Screen Petugas Area Tunggu')
@section('page-subtitle', 'Panggil antrean laki-laki dan perempuan tanpa mengubah tahap peserta')

@section('content')
    <div
        x-data="waitingQueue(@js($queueJson), @js($dataUrl), {{ $event->id }})"
        class="mx-auto max-w-6xl space-y-6"
    >
        <section class="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-900 p-6 text-white shadow-xl shadow-slate-200 sm:p-8">
            <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-indigo-200">{{ $event->code }} · Area Tunggu</p>
                    <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">Screen Petugas</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-200 sm:text-base">NEXT memanggil peserta Menunggu berikutnya. SKIP tetap menyimpan peserta pada tahap Menunggu. GOTO memanggil kembali nomor aktif.</p>
                </div>
                <a href="{{ route('events.operations.waiting', $event) }}" class="btn min-h-12 border-white/20 bg-white/10 text-white hover:bg-white/20">LIHAT LAYAR TUNGGU</a>
            </div>
        </section>

        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ $errors->first() }}</div>
        @endif

        @include('operations.partials.waiting-lanes', ['showControls' => true])
    </div>
@endsection
