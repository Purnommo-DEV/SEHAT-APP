@extends('layouts.app')

@section('title', 'Tiket Registrasi '.$eventParticipant->formattedRegistrationNumber($event->settings))
@section('page-title', 'Tiket Registrasi')
@section('page-subtitle', 'Bukti registrasi ulang peserta')

@section('content')
    <div
        class="mx-auto max-w-3xl space-y-5"
        x-data="{ cancelled: @js($eventParticipant->status === \App\Enums\ParticipantStatus::Cancelled) }"
        @sehat:operation-completed.window="if ($event.detail.form === $refs.cancelRegistrationForm) cancelled = true"
    >
        <div class="print-hidden flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('events.check-ins.index', $event) }}" class="btn btn-ghost">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                Kembali ke meja
            </a>
            <div class="grid gap-2 sm:flex sm:items-center">
                @can('cancel', $eventParticipant)
                    <form
                        x-ref="cancelRegistrationForm"
                        x-show="! cancelled"
                        method="POST"
                        action="{{ route('events.check-ins.cancel', [$event, $eventParticipant]) }}"
                        data-realtime-submit
                        data-confirm-title="Batalkan registrasi?"
                        data-confirm-message="Nomor registrasi dan nomor layanan aktif akan dilepas. Histori tetap tersimpan."
                        data-confirm-variant="warning"
                    >
                        @csrf
                        <button type="submit" class="btn btn-outline border-rose-200 text-rose-700 hover:border-rose-600 hover:bg-rose-600 hover:text-white">Batalkan registrasi</button>
                    </form>
                @endcan
                <button type="button" onclick="window.print()" class="btn border-0 bg-emerald-600 text-white hover:bg-emerald-700">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7v-7Z"/></svg>
                    Cetak tiket
                </button>
            </div>
        </div>

        <div
            x-show="cancelled"
            x-cloak
            role="status"
            class="print-hidden rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700"
        >
            Registrasi telah dibatalkan. Nomor aktif dilepas, sedangkan tiket ini tetap menjadi histori.
        </div>

        <article @class([
            'print-ticket overflow-hidden rounded-3xl border bg-white shadow-xl',
            'border-rose-200 shadow-rose-100/60' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female,
            'border-sky-200 shadow-sky-100/60' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Male,
        ])>
            <header class="bg-emerald-700 px-6 py-5 text-center text-white">
                <p class="text-xs font-bold uppercase tracking-[0.24em] text-emerald-100">SEHAT-APP · REGISTRASI ULANG</p>
                <h1 class="mt-2 text-xl font-extrabold">{{ $event->name }}</h1>
                <p class="mt-1 text-xs text-emerald-100">{{ $event->code }} · {{ $event->location }}</p>
            </header>

            <div class="px-6 py-8 text-center sm:px-10">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Nomor registrasi</p>
                <p @class([
                    'mt-2 font-mono text-6xl font-black tracking-tight sm:text-8xl',
                    'text-rose-700' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female,
                    'text-indigo-700' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Male,
                ])>{{ $eventParticipant->formattedRegistrationNumber($event->settings) }}</p>
                <span
                    class="mt-4 inline-flex rounded-full px-3 py-1.5 text-xs font-bold"
                    :class="cancelled ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'"
                    x-text="cancelled ? 'Dibatalkan' : @js($queueTicket->status->label())"
                ></span>

                <div class="mt-8 grid gap-4 border-t border-dashed border-slate-300 pt-7 text-left sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Peserta</p>
                        <p class="mt-1 font-extrabold text-slate-900">{{ $eventParticipant->participant->name }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ $eventParticipant->participant->phone ?: 'Nomor HP belum tersedia' }}</p>
                        <span @class([
                            'mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-bold ring-1',
                            'bg-rose-100 text-rose-800 ring-rose-200' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female,
                            'bg-sky-100 text-sky-800 ring-sky-200' => $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Male,
                        ])>
                            {{ $eventParticipant->participant->gender === \App\Enums\ParticipantGender::Female ? '👩' : '👨' }}
                            {{ $eventParticipant->participant->gender->label() }}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Layanan dipilih</p>
                        <p class="mt-1 font-extrabold text-slate-900">{{ $eventParticipant->services->map(fn ($service) => $service->service->label())->join(' + ') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Tujuan berikutnya</p>
                        <p class="mt-1 font-extrabold text-slate-900">{{ $queueTicket->servicePost->name }}</p>
                        <p class="mt-1 text-sm text-slate-500">Nomor layanan saat ini: {{ $queueTicket->formattedNumber() }}</p>
                    </div>
                </div>
            </div>

            <footer class="border-t border-slate-100 bg-slate-50 px-6 py-4 text-center text-xs font-medium text-slate-500">
                Check-in {{ $eventParticipant->checked_in_at->translatedFormat('d F Y, H:i:s') }}
                @if ($eventParticipant->checkedInBy)
                    · oleh {{ $eventParticipant->checkedInBy->name }}
                @endif
            </footer>
        </article>
    </div>
@endsection
