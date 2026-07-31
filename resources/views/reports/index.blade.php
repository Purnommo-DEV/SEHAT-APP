@extends('layouts.app')

@section('title', 'Laporan')
@section('page-title', 'Laporan')
@section('page-subtitle', 'Rekap operasional dan export event')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-bold text-emerald-700">REKAP OPERASIONAL</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Laporan Event</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Pilih event untuk melihat statistik, riwayat pelayanan per pos, dan mengunduh dokumen Excel atau PDF.</p>
            </div>
            @if ($snapshot)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('reports.excel', ['event_id' => $snapshot->event->id]) }}" class="btn border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"/><path stroke-linecap="round" d="M14 3v6h6M8 13h8M8 17h8"/></svg>
                        Excel
                    </a>
                    <a href="{{ route('reports.pdf', ['event_id' => $snapshot->event->id]) }}" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"/><path stroke-linecap="round" d="M9 13h6m-6 4h4"/></svg>
                        PDF
                    </a>
                </div>
            @endif
        </div>

        <form method="GET" action="{{ route('reports.index') }}" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:flex sm:items-end sm:gap-4 sm:p-6">
            <label class="form-control min-w-0 flex-1">
                <span class="label-text mb-2 font-bold text-slate-700">Event</span>
                <select name="event_id" class="select select-bordered w-full bg-white focus:border-emerald-500 focus:outline-none" onchange="this.form.submit()">
                    @forelse ($events as $event)
                        <option value="{{ $event->id }}" @selected($snapshot?->event->id === $event->id)>{{ $event->code }} · {{ $event->name }}{{ $event->active_marker === 'active' ? ' (Aktif)' : '' }}</option>
                    @empty
                        <option>Tidak ada event</option>
                    @endforelse
                </select>
            </label>
            <noscript><button type="submit" class="btn border-0 bg-emerald-600 text-white">Tampilkan</button></noscript>
        </form>

        @if ($snapshot)
            @php($reportDataUrl = route('reports.data', ['event_id' => $snapshot->event->id]))
            <div x-data="reportDashboard(@js($snapshot->toArray()), @js($reportDataUrl), {{ $snapshot->event->id }})" class="space-y-6">
                <section class="rounded-3xl bg-gradient-to-br from-emerald-700 to-teal-700 p-6 text-white shadow-xl shadow-emerald-200/60 sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-100" x-text="snapshot.event.code"></p>
                    <h2 class="mt-2 text-2xl font-black tracking-tight sm:text-3xl" x-text="snapshot.event.name"></h2>
                    <p class="mt-2 text-sm text-emerald-50" x-text="snapshot.event.location || 'Lokasi belum diatur'"></p>
                </section>

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <template x-for="card in metricCards" :key="card.key">
                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500" x-text="card.label"></p>
                            <p class="mt-2 text-3xl font-black text-slate-900" x-text="snapshot.metrics[card.key]"></p>
                        </article>
                    </template>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                        <div>
                            <h2 class="font-extrabold text-slate-900">Rekap peserta</h2>
                            <p class="mt-1 text-sm text-slate-500"><span x-text="snapshot.records.length"></span> peserta pada event ini.</p>
                        </div>
                        <span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span>
                    </div>
                    <div x-show="snapshot.records.length === 0" class="px-6 py-16 text-center text-sm text-slate-500">Belum ada peserta pada event ini.</div>
                    <div x-show="snapshot.records.length > 0" x-cloak class="overflow-x-auto">
                        <table class="table min-w-[1200px]">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Registrasi / Peserta</th><th>Layanan</th><th>Pos / Status</th><th>Riwayat antrean</th><th>Riwayat layanan</th></tr></thead>
                            <tbody>
                                <template x-for="record in snapshot.records" :key="record.id">
                                    <tr class="hover:bg-slate-50/80">
                                        <td><p class="font-mono text-sm font-black text-emerald-700" x-text="record.registration_number || '—'"></p><p class="mt-1 font-bold text-slate-900" x-text="record.name"></p><p class="mt-1 text-xs text-slate-500" x-text="[record.phone, record.nik].filter(Boolean).join(' · ')"></p></td>
                                        <td><p class="font-semibold text-slate-700" x-text="record.selected_services_text || 'Data lama'"></p><p x-show="record.donor_service_status_label" class="mt-1 text-xs text-slate-500">Donor: <span x-text="record.donor_service_status_label"></span></p><p x-show="record.health_check_status_label" class="mt-1 text-xs text-slate-500">Kesehatan: <span x-text="record.health_check_status_label"></span></p></td>
                                        <td><p class="font-semibold text-slate-700" x-text="record.current_post"></p><span class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600" x-text="record.status_label"></span></td>
                                        <td>
                                            <p x-show="record.service_history.length === 0" class="text-sm text-slate-400">—</p>
                                            <ol x-show="record.service_history.length > 0" class="space-y-2">
                                                <template x-for="history in record.service_history" :key="history.ticket_id">
                                                    <li><p class="font-mono text-sm font-black text-emerald-700" x-text="history.queue_number"></p><p class="mt-0.5 text-xs font-semibold text-slate-500"><span x-text="history.post_name"></span> · <span x-text="history.queue_status_label"></span></p></li>
                                                </template>
                                            </ol>
                                        </td>
                                        <td>
                                            <p x-show="record.service_history.length === 0" class="text-sm text-slate-400">Belum ada layanan selesai.</p>
                                            <ol x-show="record.service_history.length > 0" class="space-y-2">
                                                <template x-for="history in record.service_history" :key="`${history.ticket_id}-detail`">
                                                    <li><p class="text-sm font-bold text-slate-700"><span x-text="history.post_name"></span><span x-show="history.behavior_label" class="font-medium text-slate-400"> · <span x-text="history.behavior_label"></span></span></p><p class="mt-0.5 max-w-72 text-xs leading-5 text-slate-500" x-text="history.details || (history.completed_at ? formatDate(history.completed_at) : 'Belum selesai')"></p></li>
                                                </template>
                                            </ol>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        @else
            <section class="rounded-3xl border border-dashed border-emerald-200 bg-white p-12 text-center shadow-sm">
                <p class="text-xl font-black text-slate-900">Belum ada event untuk dilaporkan</p>
                <p class="mt-2 text-sm text-slate-500">Buat event terlebih dahulu agar rekap operasional dapat disusun.</p>
            </section>
        @endif
    </div>
@endsection
