@extends('layouts.monitor')

@section('title', 'TV Monitor')

@section('content')
    <main
        x-data="monitorBoard(@js($snapshot), @js($dataUrl))"
        @dblclick="toggleFullscreen()"
        class="min-h-screen bg-[radial-gradient(circle_at_top,_#065f46,_#020617_58%)] px-5 py-6 sm:px-8 sm:py-9 lg:px-12"
    >
        <div class="mx-auto flex min-h-[calc(100vh-3rem)] max-w-[1800px] flex-col">
            <header class="flex flex-col justify-between gap-4 border-b border-white/15 pb-6 sm:flex-row sm:items-end">
                <div>
                    <p class="text-sm font-bold uppercase tracking-[0.24em] text-emerald-200">SEHAT-APP · TV MONITOR</p>
                    <h1 class="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl" x-text="snapshot.event?.name ?? 'Menunggu event aktif'"></h1>
                    <p class="mt-2 text-base text-emerald-100" x-text="[snapshot.event?.code, snapshot.event?.location].filter(Boolean).join(' · ') || 'Layar informasi antrean' "></p>
                </div>
                <div class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-left backdrop-blur" x-data="liveClock">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-100">Waktu</p>
                    <p class="mt-1 text-2xl font-black tabular-nums" x-text="time">--:--:--</p>
                </div>
            </header>

            <div x-show="! snapshot.event" x-cloak class="flex flex-1 items-center justify-center py-16 text-center">
                <div>
                    <p class="text-4xl font-black text-white sm:text-6xl">Belum ada event aktif</p>
                    <p class="mt-4 text-lg text-emerald-100">Informasi antrean akan tampil saat event diaktifkan.</p>
                </div>
            </div>

            <section x-show="snapshot.event" x-cloak class="grid flex-1 gap-5 py-7 lg:grid-cols-2 lg:py-10">
                <template x-for="queue in snapshot.queues" :key="queue.id">
                    <article
                        class="flex min-h-[30rem] flex-col overflow-hidden rounded-[2rem] border shadow-2xl shadow-slate-950/30 backdrop-blur-sm"
                        :class="queue.current ? $store.ui.tvGenderCardClass(queue.current.participant_gender) : 'border-white/15 bg-white/10'"
                    >
                        <header class="border-b border-white/15 px-6 py-5 text-center">
                            <h2 class="text-xl font-extrabold text-emerald-100 sm:text-2xl" x-text="queue.label"></h2>
                            <p class="mt-3 inline-flex rounded-full bg-white/10 px-3 py-1.5 text-xs font-black text-emerald-50 ring-1 ring-white/15">
                                Donor: <span class="ml-1" x-text="`${queue.donation_capacity.active} / ${queue.donation_capacity.capacity} aktif - ${queue.donation_capacity.available} slot`"></span>
                            </p>
                            <p class="mt-1 text-xs font-bold uppercase tracking-[0.2em] text-white/50" x-text="queue.current?.status_label ?? queue.behavior_label"></p>
                        </header>
                        <div class="flex flex-1 flex-col items-center justify-center px-5 py-7 text-center">
                            <p class="font-mono text-[clamp(4rem,9vw,8rem)] font-black leading-none tracking-tighter text-white" x-text="queue.current?.number ?? '—'"></p>
                            <p class="mt-5 min-h-8 text-2xl font-black uppercase tracking-wide text-white sm:text-3xl" x-text="queue.current?.participant_name ?? 'Belum ada panggilan'"></p>
                            <template x-if="queue.current">
                                <div class="mt-4 space-y-3">
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <span class="inline-flex rounded-full px-3 py-1.5 text-sm font-bold" :class="$store.ui.tvGenderBadgeClass(queue.current.participant_gender)">
                                            <span class="mr-1" x-text="$store.ui.genderIcon(queue.current.participant_gender)"></span>
                                            <span x-text="queue.current.participant_gender_label"></span>
                                        </span>
                                        <span class="inline-flex rounded-full bg-emerald-300/20 px-3 py-1.5 text-sm font-bold text-emerald-100 ring-1 ring-emerald-200/30" x-text="queue.current.status_label"></span>
                                    </div>
                                    <p class="text-lg font-extrabold text-emerald-100 sm:text-xl" x-text="queue.current.service_name"></p>
                                    <p class="rounded-2xl bg-white/10 px-5 py-3 text-lg font-bold text-white sm:text-2xl" x-text="queue.current.instruction"></p>
                                </div>
                            </template>
                        </div>
                        <div class="border-t border-white/15 bg-slate-950/20 px-5 py-5">
                            <p class="text-center text-xs font-bold uppercase tracking-[0.18em] text-white/50">Antrean berikutnya</p>
                            <div x-show="queue.waiting.length === 0" class="mt-4 text-center text-base font-semibold text-white/60">Tidak ada antrean menunggu</div>
                            <ol x-show="queue.waiting.length > 0" x-cloak class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <template x-for="ticket in queue.waiting" :key="ticket.id">
                                    <li class="rounded-xl px-2 py-3 text-center" :class="$store.ui.tvGenderCardClass(ticket.participant_gender)">
                                        <p class="font-mono text-lg font-black text-white sm:text-2xl" x-text="ticket.number"></p>
                                        <p class="mt-1 truncate text-[0.65rem] font-semibold text-emerald-100" x-text="ticket.participant_name"></p>
                                        <p class="mt-1 truncate text-[0.6rem] font-bold text-white/60" x-text="ticket.service_name"></p>
                                    </li>
                                </template>
                            </ol>
                        </div>
                    </article>
                </template>
            </section>

            <section x-show="snapshot.event && snapshot.queues.length === 0" x-cloak class="flex flex-1 items-center justify-center py-16 text-center">
                <div>
                    <p class="text-3xl font-black text-white sm:text-4xl">Belum ada pos pelayanan aktif</p>
                    <p class="mt-3 text-base text-emerald-100">Informasi antrean akan tampil otomatis setelah jalur pelayanan dikonfigurasi.</p>
                </div>
            </section>

            <footer class="pt-2 text-center text-xs font-semibold tracking-wide text-white/40">
                Klik ganda layar atau tekan F11 untuk mode layar penuh ·
                {{ config('foundation.realtime.driver') === 'polling'
                    ? 'Data diperbarui berkala setiap '.(config('foundation.realtime.polling_interval_ms') / 1000).' detik'
                    : 'Data diperbarui realtime' }}
            </footer>
        </div>
    </main>
@endsection
