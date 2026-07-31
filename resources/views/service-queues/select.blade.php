@extends('layouts.app')

@section('title', 'Antrean Saya')
@section('page-title', 'Antrean Saya')
@section('page-subtitle', 'Pos pelayanan yang menjadi tanggung jawab Anda')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6">
        @if ($event === null)
            <section class="rounded-3xl border border-amber-200 bg-amber-50 p-8 text-center">
                <h1 class="text-xl font-extrabold text-amber-900">Belum ada event aktif</h1>
                <p class="mt-2 text-sm text-amber-800">Antrean pelayanan akan tersedia setelah administrator mengaktifkan event.</p>
            </section>
        @elseif ($servicePosts->isEmpty())
            <section class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h1 class="text-xl font-extrabold text-slate-900">Belum ada pos yang ditugaskan</h1>
                <p class="mt-2 text-sm text-slate-500">Hubungi administrator agar Anda ditambahkan sebagai petugas pada Jalur Pelayanan.</p>
            </section>
        @else
            <div>
                <p class="text-sm font-bold text-emerald-700">{{ $event->code }} · EVENT AKTIF</p>
                <h1 class="mt-1 text-3xl font-black text-slate-900">{{ $event->name }}</h1>
                <p class="mt-2 text-sm text-slate-500">Pilih pos yang akan Anda layani.</p>
            </div>
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($servicePosts as $post)
                    <a href="{{ route('events.service-queues.index', [$event, $post]) }}" class="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-lg">
                        <div class="flex items-start justify-between">
                            <span class="flex size-11 items-center justify-center rounded-2xl bg-emerald-50 font-mono font-black text-emerald-700">{{ str_pad((string) $post->sequence, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ $post->behavior->label() }}</span>
                        </div>
                        <h2 class="mt-5 text-lg font-extrabold text-slate-900 group-hover:text-emerald-700">{{ $post->name }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $post->description ?: $post->behavior->description() }}</p>
                    </a>
                @endforeach
            </section>
        @endif
    </div>
@endsection
