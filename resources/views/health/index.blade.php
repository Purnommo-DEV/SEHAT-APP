@extends('layouts.app')

@section('title', 'Pemeriksaan Kesehatan')
@section('page-title', 'Pemeriksaan Kesehatan')
@section('page-subtitle', 'Panggil, layani, dan konfirmasi layanan peserta')

@section('content')
    <div
        x-data="healthQueue(@js($ticketsJson), @js(route('events.health.data', $event)), {{ $event->id }})"
        class="mx-auto max-w-7xl space-y-6"
    >
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-bold text-emerald-700">{{ $event->code }} · EVENT AKTIF</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Antrean Pemeriksaan</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Panggil nomor, mulai pelayanan, lalu konfirmasi bahwa layanan telah diberikan. Tidak ada data medis yang perlu diisi.</p>
            </div>
            <span class="inline-flex items-center gap-2 self-start rounded-full border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-bold text-emerald-700 sm:self-auto">
                <span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span>
                <span x-text="isRefreshing ? 'Memperbarui' : 'Realtime aktif'"></span>
            </span>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Menunggu</p>
                <p class="mt-2 text-3xl font-black text-slate-900" x-text="waitingTickets.length"></p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-amber-600">Sedang dipanggil</p>
                <p class="mt-2 text-3xl font-black text-amber-900" x-text="callingTickets.length"></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-600">Sedang dilayani</p>
                <p class="mt-2 text-3xl font-black text-emerald-900" x-text="servingTickets.length"></p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(22rem,0.75fr)]">
            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                    <h2 class="font-extrabold text-slate-900">Antrean aktif</h2>
                    <p class="mt-1 text-sm text-slate-500">Urut berdasarkan nomor kedatangan.</p>
                </div>

                <div x-show="activeTickets.length === 0" class="px-6 py-16 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10l4 4v10l-4 4H7l-4-4V7l4-4Z"/><path stroke-linecap="round" d="M8 12h8"/></svg>
                    </div>
                    <p class="mt-4 font-bold text-slate-800">Tidak ada antrean aktif</p>
                    <p class="mt-1 text-sm text-slate-500">Peserta yang baru check-in akan tampil otomatis.</p>
                </div>

                <ol x-show="activeTickets.length > 0" x-cloak class="divide-y divide-slate-100">
                    <template x-for="ticket in activeTickets" :key="ticket.id">
                        <li class="flex flex-col gap-4 border-l-4 px-5 py-5 sm:flex-row sm:items-center sm:px-6" :class="$store.ui.genderCardClass(ticket.participant.gender)">
                            <div class="flex min-w-0 flex-1 items-center gap-4">
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl font-mono text-xl font-black shadow-lg" :class="$store.ui.genderNumberClass(ticket.participant.gender)" x-text="ticket.formatted_number"></span>
                                <div class="min-w-0">
                                    <p class="truncate font-extrabold text-slate-900" x-text="ticket.participant.name"></p>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="rounded-full px-2.5 py-1 font-bold" :class="$store.ui.genderBadgeClass(ticket.participant.gender)">
                                            <span x-text="$store.ui.genderIcon(ticket.participant.gender)"></span>
                                            <span x-text="ticket.participant.gender_label"></span>
                                        </span>
                                        <span class="rounded-full px-2.5 py-1 font-bold" :class="statusClass(ticket.status)" x-text="ticket.status_label"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2 sm:justify-end">
                                <template x-if="['waiting', 'skipped'].includes(ticket.status)">
                                    <form :action="ticket.urls.call" method="POST" data-realtime-submit data-operation-kind="health">
                                        @csrf
                                        <button type="submit" class="btn btn-sm border-0 bg-amber-500 text-white hover:bg-amber-600">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10v4m4-7v10m4-13v16m4-12v8m4-5v2"/></svg>
                                            Panggil
                                        </button>
                                    </form>
                                </template>
                                <template x-if="ticket.status === 'calling'">
                                    <div class="flex gap-2">
                                        <form :action="ticket.urls.start" method="POST" data-realtime-submit data-operation-kind="health">
                                            @csrf
                                            <button type="submit" class="btn btn-sm border-0 bg-emerald-600 text-white hover:bg-emerald-700">Mulai periksa</button>
                                        </form>
                                        <form :action="ticket.urls.skip" method="POST" data-realtime-submit data-operation-kind="health" :data-confirm-title="'Lewati nomor ' + ticket.formatted_number + '?'" data-confirm-message="Nomor tetap tersimpan dan dapat dipanggil kembali.">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost btn-sm text-slate-600">Lewati</button>
                                        </form>
                                    </div>
                                </template>
                                <template x-if="ticket.status === 'serving'">
                                    <a :href="ticket.urls.assessment" class="btn w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700 sm:btn-sm sm:w-auto">Konfirmasi layanan</a>
                                </template>
                            </div>
                        </li>
                    </template>
                </ol>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="font-extrabold text-slate-900">Selesai terbaru</h2>
                    <p class="mt-1 text-xs text-slate-500">20 pemeriksaan terakhir</p>
                </div>
                <div x-show="finishedTickets.length === 0" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada pemeriksaan selesai.</div>
                <ol x-show="finishedTickets.length > 0" x-cloak class="max-h-[34rem] divide-y divide-slate-100 overflow-y-auto">
                    <template x-for="ticket in finishedTickets" :key="ticket.id">
                        <li>
                            <a :href="ticket.urls.assessment" class="flex items-center gap-3 border-l-4 px-5 py-4 transition hover:brightness-[0.98]" :class="$store.ui.genderCardClass(ticket.participant.gender)">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl font-mono font-black shadow" :class="$store.ui.genderNumberClass(ticket.participant.gender)" x-text="ticket.formatted_number"></span>
                                <span class="min-w-0">
                                    <span class="block truncate font-bold text-slate-900" x-text="ticket.participant.name"></span>
                                    <span class="mt-0.5 block text-xs font-semibold text-emerald-700">Layanan terkonfirmasi</span>
                                </span>
                                <svg class="ml-auto size-4 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m9 18 6-6-6-6"/></svg>
                            </a>
                        </li>
                    </template>
                </ol>
            </section>
        </div>
    </div>
@endsection
