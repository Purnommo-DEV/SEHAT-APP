@extends('layouts.app')

@section('title', 'Konfirmasi Layanan '.$queueTicket->formattedNumber())
@section('page-title', 'Konfirmasi Layanan Kesehatan')
@section('page-subtitle', 'Konfirmasi bahwa peserta telah menerima layanan')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex items-start gap-3">
            <a href="{{ route('events.health.index', $event) }}" class="btn btn-ghost btn-sm btn-square mt-1" aria-label="Kembali">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </a>
            <div class="min-w-0">
                <p class="text-sm font-bold text-emerald-700">{{ $event->code }} · NOMOR {{ $queueTicket->formattedNumber() }}</p>
                <h1 class="mt-1 truncate text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $eventParticipant->participant->name }}</h1>
                <p class="mt-2 text-sm text-slate-500">{{ $eventParticipant->participant->phone ?: 'Nomor HP belum tersedia' }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <section @class([
            'overflow-hidden rounded-3xl border shadow-sm',
            'border-rose-200 bg-gradient-to-br from-rose-50 to-fuchsia-50/70' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female,
            'border-sky-200 bg-gradient-to-br from-sky-50 to-indigo-50/70' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Male,
        ])>
            <div class="p-5 sm:p-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <span @class([
                        'flex size-20 shrink-0 items-center justify-center rounded-3xl font-mono text-2xl font-black text-white shadow-lg',
                        'bg-rose-600 shadow-rose-200' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female,
                        'bg-indigo-600 shadow-indigo-200' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Male,
                    ])>
                        {{ $queueTicket->formattedNumber() }}
                    </span>
                    <div class="min-w-0">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1',
                            'bg-rose-100 text-rose-800 ring-rose-200' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female,
                            'bg-sky-100 text-sky-800 ring-sky-200' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Male,
                        ])>
                            {{ $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female ? '👩' : '👨' }}
                            {{ $eventParticipant->participant->gender->label() }}
                        </span>
                        <h2 class="mt-3 text-xl font-extrabold text-slate-950">{{ $eventParticipant->participant->name }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Pos {{ $queueTicket->servicePost->name }}</p>
                    </div>
                </div>
            </div>

            @if ($healthAssessment)
                <div class="border-t border-emerald-200 bg-emerald-50 p-5 text-center sm:p-7">
                    <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-600 text-white">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4 4L19 7"/></svg>
                    </span>
                    <p class="mt-3 text-lg font-extrabold text-emerald-950">Layanan kesehatan telah dikonfirmasi</p>
                    <p class="mt-1 text-sm text-emerald-800">Selesai pada {{ $queueTicket->finished_at?->translatedFormat('d F Y, H:i') ?? 'waktu pelayanan' }}.</p>
                    <a href="{{ route('events.health.index', $event) }}" class="btn mt-5 border-0 bg-emerald-600 text-white hover:bg-emerald-700">Kembali ke antrean</a>
                </div>
            @else
                @if ($queueTicket->status->value !== 'serving')
                    <div class="border-t border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900">
                        Panggil dan mulai pelayanan peserta terlebih dahulu dari halaman antrean.
                    </div>
                @endif

                <form
                    method="POST"
                    data-realtime-submit
                    data-operation-kind="health"
                    action="{{ route('events.health.assessments.store', [$event, $queueTicket]) }}"
                    class="border-t border-slate-200 bg-white p-5 sm:p-7"
                >
                    @csrf
                    <div class="rounded-2xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                        Tidak ada data medis yang perlu diisi. Tekan tombol di bawah setelah layanan kesehatan benar-benar diberikan.
                    </div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-[auto_minmax(0,1fr)]">
                        <a href="{{ route('events.health.index', $event) }}" class="btn btn-ghost">Kembali</a>
                        <button
                            type="submit"
                            @disabled($queueTicket->status->value !== 'serving')
                            class="btn h-13 border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700 disabled:bg-slate-300"
                        >
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4 4L19 7"/></svg>
                            Selesaikan Pemeriksaan
                        </button>
                    </div>
                </form>
            @endif
        </section>
    </div>
@endsection
