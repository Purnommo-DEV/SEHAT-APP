@extends('layouts.app')

@section('title', $servicePost->name)
@section('page-title', 'Antrean Saya')
@section('page-subtitle', 'Kelola peserta pada pos pelayanan aktif')

@section('content')
    <div
        x-data="serviceQueue(@js($ticketsJson), @js($dataUrl), {{ $event->id }}, {{ $servicePost->id }})"
        class="mx-auto max-w-7xl space-y-6"
    >
        <section class="rounded-3xl bg-gradient-to-br from-emerald-700 to-teal-600 p-6 text-white shadow-xl shadow-emerald-200 sm:p-8">
            <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                <div>
                    <a href="{{ route('queues.active') }}" class="text-sm font-bold text-emerald-100 hover:text-white">← Ganti pos</a>
                    <p class="mt-4 text-xs font-bold uppercase tracking-[0.2em] text-emerald-100">{{ $event->code }} · Pos {{ $servicePost->sequence }}</p>
                    <h1 class="mt-2 text-3xl font-black">{{ $servicePost->name }}</h1>
                    <p class="mt-2 text-sm text-emerald-50">{{ $servicePost->behavior->description() }}</p>
                </div>
                <div class="rounded-2xl bg-white/15 px-5 py-4 backdrop-blur">
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-100">Menunggu</p>
                    <p class="mt-1 text-3xl font-black" x-text="tickets.filter(ticket => ['waiting', 'skipped'].includes(ticket.status)).length"></p>
                </div>
            </div>
        </section>

        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ $errors->first() }}</div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 class="font-extrabold text-slate-900">Peserta di pos ini</h2>
                    <p class="mt-1 text-sm text-slate-500">Urutan diperbarui otomatis melalui realtime.</p>
                </div>
                <span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span>
            </div>

            <div x-show="tickets.length === 0" class="px-6 py-16 text-center text-sm text-slate-500">Belum ada peserta pada pos ini.</div>
            <div x-show="tickets.length > 0" x-cloak class="divide-y divide-slate-100">
                <template x-for="ticket in tickets" :key="ticket.id">
                    <article class="border-l-4 p-5 sm:p-6" :class="$store.ui.genderCardClass(ticket.participant.gender_value)">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
                            <div class="flex min-w-0 flex-1 items-start gap-4">
                                <div class="flex min-w-24 items-center justify-center rounded-2xl px-4 py-3 font-mono text-2xl font-black shadow-lg" :class="$store.ui.genderNumberClass(ticket.participant.gender_value)" x-text="ticket.number"></div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-extrabold text-slate-900" x-text="ticket.participant.name"></h3>
                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700" x-text="ticket.status_label"></span>
                                        <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(ticket.participant.gender_value)">
                                            <span x-text="$store.ui.genderIcon(ticket.participant.gender_value)"></span>
                                            <span x-text="ticket.participant.gender"></span>
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-500" x-text="[ticket.participant.phone, ticket.participant.gender].filter(Boolean).join(' · ')"></p>
                                    <p class="mt-1 text-xs font-semibold text-emerald-700" x-text="ticket.participant.services.join(' + ')"></p>
                                </div>
                            </div>

                            <div x-show="! ['finished', 'cancelled'].includes(ticket.status)" class="flex flex-wrap gap-2">
                                <form x-show="['waiting', 'skipped'].includes(ticket.status)" :action="ticket.urls.call" method="POST" data-realtime-submit>
                                    @csrf
                                    <button class="btn btn-sm btn-outline">Panggil</button>
                                </form>
                                <form x-show="ticket.status === 'calling'" :action="ticket.urls.start" method="POST" data-realtime-submit>
                                    @csrf
                                    <button class="btn btn-sm border-0 bg-sky-600 text-white">Mulai</button>
                                </form>
                                <form x-show="['waiting', 'calling'].includes(ticket.status)" :action="ticket.urls.skip" method="POST" data-realtime-submit>
                                    @csrf
                                    <button class="btn btn-sm btn-ghost text-amber-700">Lewati</button>
                                </form>
                                <form x-show="['waiting', 'calling', 'skipped'].includes(ticket.status)" :action="ticket.urls.cancel" method="POST" data-realtime-submit data-confirm-title="Batalkan nomor antrean?" data-confirm-message="Nomor akan dilepas dan dapat digunakan kembali." data-confirm-variant="warning">
                                    @csrf
                                    <button class="btn btn-sm btn-ghost text-rose-700">Batalkan</button>
                                </form>
                            </div>
                        </div>

                        <form
                            x-show="ticket.status === 'serving'"
                            :action="ticket.urls.complete"
                            method="POST"
                            data-realtime-submit
                            class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4"
                        >
                            @csrf
                            @if ($servicePost->behavior === \App\Enums\ServicePostBehavior::HealthForm)
                                <p class="rounded-xl bg-white p-4 text-sm leading-6 text-slate-600">Konfirmasi bahwa peserta telah menerima layanan kesehatan. Tidak ada data medis yang perlu diisi.</p>
                            @elseif ($servicePost->behavior === \App\Enums\ServicePostBehavior::ScreeningForm)
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <select name="result" aria-label="Hasil kelayakan donor" class="select select-bordered bg-white" required>
                                        <option value="eligible">Layak</option>
                                        <option value="not_eligible">Tidak layak</option>
                                    </select>
                                    <input name="reason" aria-label="Alasan tidak layak" class="input input-bordered bg-white" placeholder="Alasan jika tidak layak">
                                </div>
                            @elseif ($servicePost->behavior === \App\Enums\ServicePostBehavior::DonationForm)
                                <textarea name="notes" aria-label="Catatan donor" class="textarea textarea-bordered w-full bg-white" placeholder="Catatan donor (opsional)"></textarea>
                            @elseif ($servicePost->behavior === \App\Enums\ServicePostBehavior::CustomForm)
                                <textarea name="notes" aria-label="Catatan pelayanan" required class="textarea textarea-bordered w-full bg-white" placeholder="Catatan pelayanan"></textarea>
                            @endif
                            <div class="mt-3 flex justify-end">
                                <button type="submit" class="btn w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto">
                                    {{ $servicePost->behavior === \App\Enums\ServicePostBehavior::HealthForm ? 'Selesaikan Pemeriksaan' : '✓ Selesai' }}
                                </button>
                            </div>
                        </form>
                    </article>
                </template>
            </div>
        </section>
    </div>
@endsection
