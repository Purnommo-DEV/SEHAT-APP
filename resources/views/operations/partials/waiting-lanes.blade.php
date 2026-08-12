<section aria-label="Peserta sedang dipanggil" class="overflow-hidden rounded-3xl border border-sky-200 bg-white shadow-sm">
    <header class="border-b border-sky-100 bg-gradient-to-r from-sky-50 to-indigo-50 px-5 py-4 sm:px-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700">Sedang Dipanggil</p>
        <h2 class="mt-1 text-xl font-black text-slate-900">Peserta yang sedang diproses</h2>
        <p class="mt-1 text-sm text-slate-600">Nomor, nama, gender, dan posisi tetap tampil saat peserta berpindah tahap.</p>
    </header>

    <p x-show="currentParticipants.length === 0" class="px-5 py-10 text-center text-sm font-semibold text-slate-500">Belum ada peserta yang sedang dipanggil.</p>

    <div x-show="currentParticipants.length > 0" class="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
        <template x-for="participant in currentParticipants" :key="participant.id">
            <article class="rounded-2xl border border-sky-100 bg-sky-50/50 p-4 shadow-sm" :class="$store.ui.genderCardClass(participant.participant.gender)">
                <div class="flex gap-4">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl font-mono text-lg font-black shadow-lg" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate font-extrabold text-slate-900" x-text="participant.participant.name"></h3>
                            <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.participant.gender)" x-text="participant.participant.gender_label"></span>
                        </div>
                        <p class="mt-2 text-xs font-bold uppercase tracking-wide text-slate-500">Layanan</p>
                        <p class="text-sm font-semibold text-slate-700" x-text="participant.services.map(service => service.label).join(' + ')"></p>
                    </div>
                </div>

                <div class="mt-4 grid gap-2 rounded-xl bg-white/75 p-3 text-sm">
                    <p><span class="font-bold text-slate-500">Status:</span> <span class="font-black text-sky-700" x-text="participant.call.status_label"></span></p>
                    <p><span class="font-bold text-slate-500">Saat ini dipanggil:</span> <span class="font-extrabold text-slate-900" x-text="participant.call.target_label"></span></p>
                    <p><span class="font-bold text-slate-500">Posisi saat ini:</span> <span class="font-extrabold text-slate-900" x-text="participant.position.label"></span></p>
                </div>

                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <form x-show="participant.can_start_health_check" :action="participant.urls.health_check" method="POST" data-realtime-submit data-operation-kind="operational">
                        @csrf
                        <button type="submit" class="btn min-h-11 w-full border-0 bg-cyan-600 text-white hover:bg-cyan-700">CEK KESEHATAN</button>
                    </form>
                    <form x-show="participant.can_donate" :action="participant.urls.donate" method="POST" data-realtime-submit data-operation-kind="operational">
                        @csrf
                        <button type="submit" class="btn min-h-11 w-full border-0 bg-rose-600 text-white hover:bg-rose-700">DONOR</button>
                    </form>
                    <form x-show="participant.can_complete_before_donation" :action="participant.urls.complete_before_donation" method="POST" data-realtime-submit data-operation-kind="operational">
                        @csrf
                        <button type="submit" class="btn min-h-11 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button>
                    </form>
                    <form x-show="participant.status === 'donating'" :action="participant.urls.complete" method="POST" data-realtime-submit data-operation-kind="operational">
                        @csrf
                        <button type="submit" class="btn min-h-11 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button>
                    </form>
                </div>
            </article>
        </template>
    </div>
</section>

<section aria-label="Antrean operasional" class="grid gap-5 lg:grid-cols-2">
    <template x-for="lane in lanes" :key="lane.value">
        <article
            class="overflow-hidden rounded-3xl border bg-white shadow-sm"
            :class="lane.cardClass"
            :data-testid="`queue-lane-${lane.value}`"
        >
            <header class="px-5 py-4 text-white sm:px-6" :class="lane.headerClass">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-white/75">Antrean Operasional</p>
                        <h2 class="mt-1 text-xl font-black" x-text="lane.label"></h2>
                    </div>
                    <span class="rounded-full bg-white/15 px-3 py-1.5 text-xs font-bold" x-text="`${waitingTicketsFor(lane.value).length} menunggu`"></span>
                </div>
            </header>

            <div class="space-y-5 p-5 sm:p-6">
                <section class="rounded-2xl border p-5 text-center" :class="lane.currentClass">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Panggilan pada jalur ini</p>
                    <p class="mt-3 font-mono text-6xl font-black tracking-tight sm:text-7xl" :class="lane.numberClass" x-text="displayNumber(currentTicketFor(lane.value)) ?? '—'"></p>
                    <p class="mt-3 truncate text-lg font-extrabold text-slate-900" x-text="currentTicketFor(lane.value)?.participant.name ?? 'Belum ada nomor dipanggil'"></p>
                    <p x-show="currentTicketFor(lane.value)" class="mt-1 text-xs font-semibold text-slate-500">Layanan: <span x-text="currentTicketFor(lane.value)?.participant.services.join(' + ')"></span></p>
                    <p x-show="currentTicketFor(lane.value)" class="mt-1 text-xs font-semibold text-slate-500">Posisi saat ini: <span x-text="currentTicketFor(lane.value)?.position.label"></span></p>
                    <p class="mt-1 text-sm font-semibold text-slate-500" x-text="currentTicketFor(lane.value)?.status_label ?? 'Belum ada panggilan aktif'"></p>
                </section>

                @if ($showControls)
                    <div class="grid gap-3 sm:grid-cols-3">
                        <form
                            :action="nextTicketFor(lane.value)?.urls.call ?? ''"
                            method="POST"
                            data-realtime-submit
                            @submit="if (!ensureNext(lane.value)) $event.preventDefault()"
                        >
                            @csrf
                            <button
                                type="submit"
                                class="btn min-h-12 w-full border-0 text-white"
                                :class="lane.nextButtonClass"
                                :disabled="!nextTicketFor(lane.value)"
                                :aria-label="`Panggil nomor berikutnya jalur ${lane.label}`"
                                :data-testid="`next-${lane.value}`"
                            >NEXT</button>
                        </form>

                        <form
                            :action="skippableTicketFor(lane.value)?.urls.skip ?? ''"
                            method="POST"
                            data-realtime-submit
                            @submit="if (!ensureSkip(lane.value)) $event.preventDefault()"
                        >
                            @csrf
                            <button
                                type="submit"
                                class="btn min-h-12 w-full border-0 bg-amber-500 text-slate-950 hover:bg-amber-400"
                                :disabled="!skippableTicketFor(lane.value)"
                                :aria-label="`Lewati nomor saat ini jalur ${lane.label}`"
                                :data-testid="`skip-${lane.value}`"
                            >SKIP</button>
                        </form>

                        <button
                            type="button"
                            class="btn min-h-12 w-full border-0 bg-slate-800 text-white hover:bg-slate-700"
                            @click="openGoto(lane.value)"
                            :aria-label="`Buka Goto Nomor jalur ${lane.label}`"
                            :data-testid="`goto-${lane.value}`"
                        >GOTO</button>
                    </div>
                @endif

                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Antrean berikutnya</p>
                    <template x-if="waitingTicketsFor(lane.value).length === 0">
                        <p class="mt-2 text-sm text-slate-500">Belum ada peserta menunggu pada jalur ini.</p>
                    </template>
                    <ol x-show="waitingTicketsFor(lane.value).length > 0" class="mt-3 space-y-2">
                        <template x-for="ticket in waitingTicketsFor(lane.value).slice(0, 4)" :key="ticket.id">
                            <li class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-3 py-2 shadow-sm">
                                <span class="shrink-0 font-mono text-base font-black" :class="lane.numberClass" x-text="displayNumber(ticket)"></span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-800" x-text="ticket.participant.name"></span><span class="block truncate text-xs font-medium text-slate-500">Layanan: <span x-text="ticket.participant.services.join(' + ')"></span> · Posisi: <span x-text="ticket.position.label"></span></span></span>
                                <span class="ml-auto shrink-0 rounded-full bg-slate-100 px-2 py-1 text-[0.65rem] font-bold text-slate-600" x-text="ticket.status_label"></span>
                            </li>
                        </template>
                    </ol>
                </div>
            </div>
        </article>
    </template>
</section>

<section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-label="Posisi peserta aktif">
    <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Posisi peserta aktif</p>
            <p class="mt-1 text-sm text-slate-600">Peserta tetap terlihat di tahap yang sedang dijalani sampai dinyatakan selesai.</p>
        </div>
        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-black text-emerald-700" x-text="activePositions.length"></span>
    </header>

    <p x-show="activePositions.length === 0" class="px-5 py-10 text-center text-sm text-slate-500">Belum ada peserta yang sedang diproses.</p>

    <ol x-show="activePositions.length > 0" class="divide-y divide-slate-100">
        <template x-for="participant in activePositions" :key="participant.id">
            <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:p-5" :class="$store.ui.genderCardClass(participant.participant.gender)">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl font-mono text-base font-black shadow-sm" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-extrabold text-slate-900" x-text="participant.participant.name"></p>
                    <p class="mt-1 truncate text-sm text-slate-600" x-text="participant.services.map(service => service.label).join(' + ')"></p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.participant.gender)">
                        <span x-text="$store.ui.genderIcon(participant.participant.gender)"></span>
                        <span x-text="participant.participant.gender_label"></span>
                    </span>
                    <span class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-black text-white" x-text="participant.position.label"></span>
                </div>
            </li>
        </template>
    </ol>
</section>

<section class="grid gap-5 lg:grid-cols-2" aria-label="Tahap pelayanan operasional">
    <article class="overflow-hidden rounded-3xl border border-cyan-100 bg-white shadow-sm">
        <header class="border-b border-cyan-100 bg-cyan-50 px-5 py-4">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-700">Cek Kesehatan</p>
            <p class="mt-1 text-sm font-semibold text-slate-600">Peserta yang telah dipanggil untuk tahap kesehatan.</p>
        </header>
        <p x-show="healthParticipants.length === 0" class="px-5 py-8 text-center text-sm text-slate-500">Belum ada peserta di cek kesehatan.</p>
        <ol x-show="healthParticipants.length > 0" class="divide-y divide-slate-100">
            <template x-for="participant in healthParticipants" :key="participant.id">
                <li class="p-4" :class="$store.ui.genderCardClass(participant.participant.gender)">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl font-mono text-base font-black shadow-sm" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span>
                        <div class="min-w-0 flex-1"><p class="truncate font-extrabold text-slate-900" x-text="participant.participant.name"></p><p class="mt-1 text-xs font-semibold text-slate-600" x-text="participant.participant.gender_label"></p></div>
                        <div class="grid shrink-0 gap-2 sm:min-w-28">
                            <form x-show="participant.can_donate" :action="participant.urls.donate" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-10 w-full border-0 bg-rose-600 text-white hover:bg-rose-700">DONOR</button></form>
                            <form x-show="participant.can_complete_before_donation" :action="participant.urls.complete_before_donation" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-10 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button></form>
                        </div>
                    </div>
                </li>
            </template>
        </ol>
    </article>

    <article class="overflow-hidden rounded-3xl border border-rose-100 bg-white shadow-sm">
        <header class="border-b border-rose-100 bg-rose-50 px-5 py-4">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Sedang Donor</p>
            <p class="mt-1 text-sm font-semibold text-slate-600">Peserta dengan donor aktif.</p>
        </header>
        <p x-show="donatingParticipants.length === 0" class="px-5 py-8 text-center text-sm text-slate-500">Belum ada peserta sedang donor.</p>
        <ol x-show="donatingParticipants.length > 0" class="divide-y divide-slate-100">
            <template x-for="participant in donatingParticipants" :key="participant.id">
                <li class="p-4" :class="$store.ui.genderCardClass(participant.participant.gender)">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl font-mono text-base font-black shadow-sm" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span>
                        <div class="min-w-0 flex-1"><p class="truncate font-extrabold text-slate-900" x-text="participant.participant.name"></p><p class="mt-1 text-xs font-semibold text-slate-600" x-text="participant.participant.gender_label"></p></div>
                        <form :action="participant.urls.complete" method="POST" data-realtime-submit data-operation-kind="operational"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button type="submit" class="btn min-h-10 border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button></form>
                    </div>
                </li>
            </template>
        </ol>
    </article>
</section>

<section class="overflow-hidden rounded-3xl border border-emerald-100 bg-white shadow-sm" aria-label="Peserta selesai">
    <header class="border-b border-emerald-100 bg-emerald-50 px-5 py-4"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Selesai</p><p class="mt-1 text-sm font-semibold text-slate-600">Riwayat peserta yang telah menyelesaikan proses.</p></header>
    <p x-show="finishedParticipants.length === 0" class="px-5 py-8 text-center text-sm text-slate-500">Belum ada peserta selesai.</p>
    <ol x-show="finishedParticipants.length > 0" class="divide-y divide-slate-100">
        <template x-for="participant in finishedParticipants.slice(0, 30)" :key="participant.id">
            <li class="flex items-center gap-3 p-4" :class="$store.ui.genderCardClass(participant.participant.gender)"><span class="font-mono text-base font-black" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span><span class="min-w-0 flex-1"><span class="block truncate font-extrabold text-slate-900" x-text="participant.participant.name"></span><span class="block text-xs font-semibold text-slate-500" x-text="participant.participant.gender_label"></span></span><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-black text-emerald-700">SELESAI</span></li>
        </template>
    </ol>
</section>

@if ($showControls)
    <div
        x-cloak
        x-show="goto.open"
        x-transition.opacity
        class="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/45 p-3 sm:items-center sm:p-6"
        role="dialog"
        aria-modal="true"
        aria-labelledby="goto-title"
        @keydown.escape.window="closeGoto()"
    >
        <div class="w-full max-w-md rounded-3xl bg-white p-5 shadow-2xl sm:p-6" @click.outside="closeGoto()">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Area Tunggu</p>
                    <h2 id="goto-title" class="mt-1 text-xl font-black text-slate-900">Goto Nomor</h2>
                    <p class="mt-1 text-sm text-slate-500">Panggil kembali nomor yang masih aktif pada jalur <span class="font-bold" x-text="laneLabel(goto.lane)"></span>.</p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm btn-square" @click="closeGoto()" aria-label="Tutup Goto Nomor">×</button>
            </div>

            <form class="mt-6 space-y-4" method="POST" data-realtime-submit @submit="if (!prepareGoto($event)) $event.preventDefault()">
                @csrf
                <input type="hidden" name="queue_lane" :value="goto.lane">
                <label class="form-control">
                    <span class="mb-2 text-sm font-bold text-slate-700">Cari peserta aktif</span>
                    <input x-model="goto.search" type="search" class="input input-bordered min-h-12 w-full bg-white" placeholder="Cari nama atau nomor registrasi" autocomplete="off" aria-label="Cari peserta aktif untuk Goto">
                </label>
                <div class="max-h-64 space-y-2 overflow-y-auto rounded-2xl border border-slate-200 bg-slate-50 p-2" role="listbox" aria-label="Peserta aktif yang dapat dipanggil">
                    <template x-if="gotoCandidates().length === 0">
                        <p class="px-3 py-4 text-sm text-slate-500">Tidak ada peserta aktif yang sesuai.</p>
                    </template>
                    <template x-for="ticket in gotoCandidates()" :key="ticket.id">
                        <button type="button" class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left transition hover:bg-white" :class="goto.selectedTicketId === ticket.id ? 'bg-white ring-2 ring-emerald-500' : 'bg-transparent'" @click="selectGotoTicket(ticket)" role="option" :aria-selected="goto.selectedTicketId === ticket.id">
                            <span class="font-mono text-base font-black text-emerald-700" x-text="displayNumber(ticket)"></span>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-900" x-text="ticket.participant.name"></span><span class="block truncate text-xs text-slate-500"><span x-text="ticket.participant.gender"></span> · <span x-text="ticket.participant.services.join(' + ')"></span></span></span>
                            <span class="rounded-full bg-slate-200 px-2 py-1 text-[0.65rem] font-bold text-slate-600" x-text="ticket.status_label"></span>
                        </button>
                    </template>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" class="btn min-h-12" @click="closeGoto()">BATAL</button>
                    <button type="submit" class="btn min-h-12 border-0 bg-emerald-600 text-white hover:bg-emerald-700" :disabled="!selectedGotoTicket()">PANGGIL</button>
                </div>
            </form>
        </div>
    </div>
@endif
