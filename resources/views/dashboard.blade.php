@extends('layouts.operational')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Pantauan operasional event secara realtime')

@section('content')
    <div x-data="dashboard(@js($snapshot), @js($dataUrl))" class="mx-auto max-w-7xl space-y-6">
        <section class="overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-700 via-emerald-700 to-teal-700 p-6 text-white shadow-xl shadow-emerald-200/60 sm:p-8">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div>
                    <p class="text-sm font-bold text-emerald-100" x-text="snapshot.event ? snapshot.event.code + ' · EVENT AKTIF' : 'RUANG KERJA PANITIA'"></p>
                    <h1 class="mt-2 max-w-3xl text-3xl font-black tracking-tight sm:text-4xl" x-text="snapshot.event?.name ?? 'Belum ada event aktif'"></h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-emerald-50/90 sm:text-base" x-text="snapshot.event ? [snapshot.event.location, formatDate(snapshot.event.starts_at)].filter(Boolean).join(' · ') : 'Aktifkan event untuk mulai memantau alur pelayanan.'"></p>
                </div>
                <div class="rounded-2xl bg-white/10 px-5 py-4 ring-1 ring-white/15 backdrop-blur" x-data="liveClock">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-100">Waktu lokal</p>
                    <p class="mt-2 text-2xl font-black tabular-nums" x-text="time">--:--:--</p>
                    <p class="mt-1 text-sm text-emerald-100" x-text="date">Memuat tanggal</p>
                </div>
            </div>
        </section>

        <div x-show="! snapshot.event" x-cloak class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-semibold text-amber-800">
            Belum ada event aktif. Statistik akan terisi otomatis saat administrator mengaktifkan event.
        </div>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <template x-for="card in metricCards" :key="card.key">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-500" x-text="card.label"></p>
                            <p class="mt-2 text-3xl font-black text-slate-900" x-text="snapshot.metrics[card.key]"></p>
                        </div>
                        <span class="flex size-11 items-center justify-center rounded-xl" :class="card.iconClass">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" x-html="card.icon"></svg>
                        </span>
                    </div>
                </article>
            </template>
        </section>

        <section x-show="snapshot.event" x-cloak class="rounded-3xl border border-rose-100 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-600">Kapasitas Donor per Gender</p>
                    <p class="mt-1 text-sm font-semibold text-slate-500">Peserta sedang donor dan slot tersisa.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-700">Total <span x-text="snapshot.donation_capacity.total.active"></span> / <span x-text="snapshot.donation_capacity.total.capacity"></span></span>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <template x-for="lane in [snapshot.donation_capacity.male, snapshot.donation_capacity.female]" :key="lane.gender">
                    <article class="rounded-2xl border p-4" :class="lane.gender === 'male' ? 'border-indigo-100 bg-indigo-50/60' : 'border-rose-100 bg-rose-50/60'">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-extrabold text-slate-900" x-text="lane.label"></p>
                            <span class="rounded-full px-2.5 py-1 text-xs font-black" :class="lane.is_full ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'" x-text="lane.is_full ? 'PENUH' : 'TERSEDIA'"></span>
                        </div>
                        <p class="mt-3 text-3xl font-black text-slate-900"><span x-text="lane.active"></span> / <span x-text="lane.capacity"></span></p>
                        <p class="mt-1 text-sm font-semibold text-slate-500"><span x-text="lane.available"></span> slot tersedia</p>
                    </article>
                </template>
            </div>
        </section>

        <section x-show="snapshot.event && snapshot.post_metrics.length > 0" x-cloak class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="font-extrabold text-slate-900">Ringkasan per pos</h2>
                <p class="mt-1 text-sm text-slate-500">Antrean aktif mengikuti urutan jalur pelayanan event.</p>
            </div>
            <div class="grid divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-3">
                <template x-for="post in snapshot.post_metrics" :key="post.id">
                    <article class="p-5">
                        <p class="font-bold text-slate-900" x-text="post.name"></p>
                        <p class="mt-1 text-xs font-semibold text-slate-500" x-text="post.behavior_label"></p>
                        <dl class="mt-4 flex gap-5 text-sm">
                            <div><dt class="text-slate-500">Menunggu</dt><dd class="mt-1 text-lg font-black text-amber-700" x-text="post.waiting"></dd></div>
                            <div><dt class="text-slate-500">Dipanggil</dt><dd class="mt-1 text-lg font-black text-sky-700" x-text="post.calling"></dd></div>
                            <div><dt class="text-slate-500">Dilayani</dt><dd class="mt-1 text-lg font-black text-emerald-700" x-text="post.serving"></dd></div>
                        </dl>
                    </article>
                </template>
            </div>
        </section>

        <section x-show="snapshot.event && snapshot.post_metrics.length === 0" x-cloak class="rounded-3xl border border-dashed border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">
            Jalur pelayanan event belum memiliki pos aktif. Tambahkan dan aktifkan pos pelayanan sebelum peserta melakukan check-in.
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(22rem,0.8fr)]">
            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="font-extrabold text-slate-900">Posisi peserta saat ini</h2>
                        <p class="mt-1 text-sm text-slate-500">Status dan pos terakhir yang tercatat.</p>
                    </div>
                    <span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span>
                </div>

                <div x-show="snapshot.current_positions.length === 0" class="px-6 py-16 text-center text-sm text-slate-500">Belum ada peserta dalam alur pelayanan.</div>
                <div x-show="snapshot.current_positions.length > 0" x-cloak class="overflow-x-auto">
                    <table class="table min-w-[650px]">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Peserta</th><th>Pos saat ini</th><th>Status</th></tr></thead>
                        <tbody>
                            <template x-for="position in snapshot.current_positions" :key="position.id">
                                <tr :class="$store.ui.genderCardClass(position.participant_gender)">
                                    <td>
                                        <p class="font-bold text-slate-900" x-text="position.participant_name"></p>
                                        <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-bold" :class="$store.ui.genderBadgeClass(position.participant_gender)">
                                            <span class="mr-1" x-text="$store.ui.genderIcon(position.participant_gender)"></span>
                                            <span x-text="position.participant_gender_label"></span>
                                        </span>
                                    </td>
                                    <td><p class="text-sm font-semibold text-slate-600" x-text="position.post_name"></p><p class="mt-1 text-xs text-slate-400" x-text="position.services.join(' + ')"></p></td>
                                    <td><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700" x-text="position.status_label"></span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                    <h2 class="font-extrabold text-slate-900">Aktivitas terbaru</h2>
                    <p class="mt-1 text-sm text-slate-500">Timeline audit event realtime.</p>
                </div>
                <div x-show="snapshot.activities.length === 0" class="px-6 py-16 text-center text-sm text-slate-500">Belum ada aktivitas operasional.</div>
                <ol x-show="snapshot.activities.length > 0" x-cloak class="max-h-[34rem] divide-y divide-slate-100 overflow-y-auto">
                    <template x-for="activity in snapshot.activities" :key="activity.id">
                        <li class="flex gap-3 px-5 py-4">
                            <span class="mt-1 flex size-2.5 shrink-0 rounded-full bg-emerald-500 ring-4 ring-emerald-50"></span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-800" x-text="activity.subject_name"></p>
                                <p class="mt-0.5 text-sm text-slate-600" x-text="activity.action_label"></p>
                                <p class="mt-1 text-xs text-slate-400" x-text="formatActivity(activity)"></p>
                            </div>
                        </li>
                    </template>
                </ol>
            </section>
        </div>
    </div>
@endsection
