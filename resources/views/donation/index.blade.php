@extends('layouts.app')

@section('title', 'Antrean Donor')
@section('page-title', 'Antrean Donor')
@section('page-subtitle', $event->settings->donor_number_mode->label())

@section('content')
    <div
        x-data="donorQueue(@js($ticketsJson), @js(route('events.donation.data', $event)), {{ $event->id }})"
        class="mx-auto max-w-7xl space-y-6"
    >
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-bold text-emerald-700">{{ $event->code }} · EVENT AKTIF</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Antrean Donor</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Mode nomor: {{ $event->settings->donor_number_mode->label() }}. Panggil, mulai pelayanan, lalu tandai donor selesai.</p>
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
                <p class="text-xs font-bold uppercase tracking-wider text-amber-600">Dipanggil</p>
                <p class="mt-2 text-3xl font-black text-amber-900" x-text="callingTickets.length"></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-600">Sedang donor</p>
                <p class="mt-2 text-3xl font-black text-emerald-900" x-text="servingTickets.length"></p>
            </div>
        </div>

        @if ($event->settings->donor_number_mode === \App\Enums\DonorNumberMode::Global)
            <div class="mx-auto max-w-4xl">
                <x-donor-queue-column
                    title="Antrean Donor Global"
                    subtitle="Prefix {{ $event->settings->donor_queue_prefix }}"
                    queue-type="donor_global"
                    accent="sky"
                />
            </div>
        @else
            <div class="grid gap-6 xl:grid-cols-2">
                <x-donor-queue-column
                    title="Donor Laki-laki"
                    subtitle="Prefix {{ $event->settings->male_donor_queue_prefix }}"
                    queue-type="male_donor"
                    accent="sky"
                />
                <x-donor-queue-column
                    title="Donor Perempuan"
                    subtitle="Prefix {{ $event->settings->female_donor_queue_prefix }}"
                    queue-type="female_donor"
                    accent="fuchsia"
                />
            </div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="font-extrabold text-slate-900">Donor selesai terbaru</h2>
                <p class="mt-1 text-xs text-slate-500">20 pelayanan terakhir</p>
            </div>
            <div x-show="finishedTickets.length === 0" class="px-6 py-10 text-center text-sm text-slate-500">Belum ada donor selesai.</div>
            <div x-show="finishedTickets.length > 0" x-cloak class="overflow-x-auto">
                <table class="table min-w-[650px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Nomor</th><th>Peserta</th><th>Kelompok</th><th>Status</th></tr></thead>
                    <tbody>
                        <template x-for="ticket in finishedTickets" :key="ticket.id">
                            <tr :class="$store.ui.genderCardClass(ticket.participant.gender)">
                                <td><span class="rounded-xl px-3 py-2 font-mono text-base font-black shadow" :class="$store.ui.genderNumberClass(ticket.participant.gender)" x-text="ticket.formatted_number"></span></td>
                                <td class="font-bold text-slate-900" x-text="ticket.participant.name"></td>
                                <td>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(ticket.participant.gender)">
                                        <span x-text="$store.ui.genderIcon(ticket.participant.gender)"></span>
                                        <span x-text="ticket.participant.gender_label"></span>
                                    </span>
                                </td>
                                <td><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Donor selesai</span></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
