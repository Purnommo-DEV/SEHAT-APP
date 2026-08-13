<section class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]" aria-label="Kendali operasional">
    <article class="overflow-hidden rounded-3xl border border-sky-200 bg-white shadow-sm xl:sticky xl:top-4 xl:self-start" aria-label="Peserta sedang dipanggil">
        <header class="border-b border-sky-100 bg-gradient-to-r from-sky-600 to-indigo-700 px-5 py-4 text-white sm:px-6">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-white/75">Sedang Dipanggil</p>
            <h2 class="mt-1 text-xl font-black">Kendali peserta saat ini</h2>
        </header>

        <template x-if="primaryParticipant">
            <div class="p-4 sm:p-5">
                <div class="rounded-2xl border border-sky-100 bg-gradient-to-br from-sky-50 to-indigo-50/70 p-4 shadow-sm sm:p-5" :class="$store.ui.genderCardClass(primaryParticipant.participant.gender)">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <span class="flex size-20 shrink-0 items-center justify-center rounded-3xl font-mono text-2xl font-black shadow-lg" :class="$store.ui.genderNumberClass(primaryParticipant.participant.gender)" x-text="primaryParticipant.number"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-black text-white">#<span x-text="primaryParticipant.registration_order"></span></span>
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(primaryParticipant.participant.gender)" x-text="primaryParticipant.participant.gender_label"></span>
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-600 shadow-sm" x-text="primaryParticipant.call.status_label"></span>
                            </div>
                            <h3 class="mt-3 truncate text-2xl font-black tracking-tight text-slate-950" x-text="primaryParticipant.participant.name"></h3>
                            <p class="mt-1 text-sm font-semibold text-slate-600">Pilihan: <span x-text="primaryParticipant.services.map(service => service.label).join(' + ')"></span></p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-2 rounded-2xl bg-white/85 p-4 text-sm shadow-sm">
                        <p class="font-bold text-slate-500">Posisi saat ini</p>
                        <p class="text-lg font-black text-slate-900" x-text="primaryParticipant.position.label"></p>
                        <p class="border-t border-slate-100 pt-2 text-sm font-semibold text-slate-600">Tindakan berikutnya: <span class="font-black text-sky-700" x-text="primaryParticipant.call.target_label"></span></p>
                        <p x-show="primaryParticipant.eligibility.result" class="rounded-xl px-3 py-2 text-sm font-black" :class="primaryParticipant.eligibility.result === 'eligible' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'" x-text="primaryParticipant.eligibility.result === 'eligible' ? 'LAYAK DONOR' : 'TIDAK LAYAK DONOR'"></p>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <form x-show="primaryParticipant.can_start_health_check" :action="primaryParticipant.urls.health_check" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-cyan-600 text-white hover:bg-cyan-700" x-text="primaryParticipant.can_continue_to_health_check ? 'LANJUT KE CEK KESEHATAN' : 'CEK KESEHATAN'"></button>
                        </form>
                        <form x-show="primaryParticipant.can_start_eligibility" :action="primaryParticipant.urls.eligibility" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-violet-600 text-white hover:bg-violet-700">CEK KELAYAKAN DONOR</button>
                        </form>
                        <form x-show="primaryParticipant.can_decide_eligibility" :action="primaryParticipant.urls.eligible" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">LAYAK DONOR</button>
                        </form>
                        <form x-show="primaryParticipant.can_decide_eligibility" :action="primaryParticipant.urls.ineligible" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-slate-700 text-white hover:bg-slate-800">TIDAK LAYAK DONOR</button>
                        </form>
                        <form x-show="primaryParticipant.can_donate" :action="primaryParticipant.urls.donate" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-rose-600 text-white hover:bg-rose-700">DONOR</button>
                        </form>
                        <form x-show="primaryParticipant.can_complete_before_donation" :action="primaryParticipant.urls.complete_before_donation" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button>
                        </form>
                        <form x-show="primaryParticipant.status === 'donating'" :action="primaryParticipant.urls.complete" method="POST" data-realtime-submit data-operation-kind="operational">
                            @csrf
                            <button type="submit" class="btn min-h-12 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button>
                        </form>
                    </div>
                </div>

                <p x-show="activePositions.length > 1" class="mt-3 text-xs font-semibold text-slate-500">Ada <span x-text="activePositions.length - 1"></span> peserta lain yang masih diproses; lihat ringkasannya di bawah.</p>
            </div>
        </template>

        <p x-show="! primaryParticipant" class="px-5 py-8 text-center text-sm font-semibold text-slate-500">Belum ada peserta yang sedang dipanggil.</p>

        @if ($showControls)
            <div class="border-t border-slate-100 bg-slate-50 p-4 sm:p-5">
                <p class="mb-3 text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Kontrol pemanggilan</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <form
                        :action="skippableTicketFor()?.urls.skip ?? ''"
                        method="POST"
                        data-realtime-submit
                        @submit="if (!ensureSkip()) $event.preventDefault()"
                    >
                        @csrf
                        <button
                            type="submit"
                            class="btn min-h-12 w-full border-0 bg-amber-500 text-slate-950 hover:bg-amber-400"
                            :disabled="!skippableTicketFor()"
                            aria-label="Lewati nomor saat ini antrean Global"
                            data-testid="skip-global"
                        >SKIP</button>
                    </form>

                    <form
                        :action="nextTicketFor()?.urls.call ?? ''"
                        method="POST"
                        data-realtime-submit
                        @submit="if (!ensureNext()) $event.preventDefault()"
                    >
                        @csrf
                        <button
                            type="submit"
                            class="btn min-h-12 w-full border-0 bg-indigo-600 text-white hover:bg-indigo-700"
                            :disabled="!nextTicketFor()"
                            aria-label="Panggil nomor berikutnya antrean Global"
                            data-testid="next-global"
                        >NEXT</button>
                    </form>

                    <button
                        type="button"
                        class="btn col-span-2 min-h-12 w-full border-0 bg-slate-800 text-white hover:bg-slate-700 sm:col-span-1"
                        @click="openGoto()"
                        aria-label="Buka Goto Nomor jalur Global"
                        data-testid="goto-global"
                    >GOTO PESERTA</button>
                </div>
            </div>
        @endif
    </article>

    <aside class="overflow-hidden rounded-3xl border border-indigo-200 bg-white shadow-sm" aria-label="Antrean berikutnya">
        <header class="border-b border-indigo-100 bg-indigo-50 px-5 py-4 sm:px-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-indigo-700">Berikutnya</p>
            <h2 class="mt-1 text-xl font-black text-slate-900">Antrean global</h2>
            <p class="mt-1 text-sm font-semibold text-slate-600">Diurutkan berdasarkan urutan registrasi.</p>
        </header>

        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                <span class="text-sm font-bold text-slate-600">Menunggu</span>
                <span class="rounded-full bg-indigo-100 px-3 py-1 text-sm font-black text-indigo-700" x-text="waitingTickets.length"></span>
            </div>

            <p x-show="waitingTickets.length === 0" class="py-8 text-center text-sm font-semibold text-slate-500">Belum ada peserta menunggu.</p>
            <ol x-show="waitingTickets.length > 0" class="mt-3 space-y-2">
                <template x-for="ticket in upcomingTickets" :key="ticket.id">
                    <li class="flex min-w-0 items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3 shadow-sm" :class="$store.ui.genderCardClass(ticket.participant.gender)">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl font-mono text-base font-black shadow-sm" :class="$store.ui.genderNumberClass(ticket.participant.gender)" x-text="displayNumber(ticket)"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-bold text-slate-500">#<span x-text="ticket.registration_order"></span></span>
                            <span class="block truncate font-extrabold text-slate-900" x-text="ticket.participant.name"></span>
                            <span class="block truncate text-xs font-semibold text-slate-600" x-text="ticket.participant.gender_label ?? ticket.participant.gender"></span>
                        </span>
                    </li>
                </template>
            </ol>
        </div>
    </aside>
</section>

<section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan status operasional">
    <article class="rounded-2xl border border-cyan-100 bg-cyan-50/70 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-cyan-700">Cek Kesehatan</p>
        <p class="mt-2 text-2xl font-black text-slate-900" x-text="healthParticipants.length"></p>
    </article>
    <article class="rounded-2xl border border-violet-100 bg-violet-50/70 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-violet-700">Kelayakan</p>
        <p class="mt-2 text-2xl font-black text-slate-900" x-text="eligibilityParticipants.length"></p>
    </article>
    <article class="rounded-2xl border border-rose-100 bg-rose-50/70 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-rose-700">Sedang Donor</p>
        <p class="mt-2 text-2xl font-black text-slate-900"><span x-text="capacity?.total?.active ?? 0"></span> / <span x-text="capacity?.total?.capacity ?? 0"></span></p>
    </article>
    <article class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Selesai</p>
        <p class="mt-2 text-2xl font-black text-slate-900" x-text="finishedParticipants.length"></p>
    </article>
</section>

<section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-label="Peserta dalam proses">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Proses berjalan</p>
            <h2 class="mt-1 text-lg font-black text-slate-900">Peserta di setiap posisi</h2>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1.5 text-sm font-black text-slate-700" x-text="activePositions.length"></span>
    </header>

    <p x-show="healthParticipants.length + eligibilityParticipants.length + donatingParticipants.length === 0" class="px-5 py-8 text-center text-sm font-semibold text-slate-500">Belum ada peserta dalam proses pelayanan.</p>

    <div x-show="healthParticipants.length + eligibilityParticipants.length + donatingParticipants.length > 0" class="grid divide-y divide-slate-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
        <section x-show="healthParticipants.length > 0" class="p-4" aria-label="Peserta cek kesehatan">
            <p class="mb-3 text-sm font-black text-cyan-700">CEK KESEHATAN</p>
            <ol class="space-y-2">
                <template x-for="participant in healthParticipants" :key="participant.id">
                    <li class="rounded-2xl border border-cyan-100 p-3" :class="$store.ui.genderCardClass(participant.participant.gender)">
                        <p class="font-mono text-base font-black text-cyan-700" x-text="participant.number"></p>
                        <p class="mt-1 truncate font-extrabold text-slate-900" x-text="participant.participant.name"></p>
                        <div class="mt-3 grid gap-2">
                            <p x-show="participant.eligibility.result" class="rounded-xl px-3 py-2 text-xs font-black" :class="participant.eligibility.result === 'eligible' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'" x-text="participant.eligibility.result === 'eligible' ? 'LAYAK DONOR' : 'TIDAK LAYAK DONOR'"></p>
                            <form x-show="participant.can_donate" :action="participant.urls.donate" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-11 w-full border-0 bg-rose-600 text-white hover:bg-rose-700">DONOR</button></form>
                            <form x-show="participant.can_complete_before_donation" :action="participant.urls.complete_before_donation" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-11 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button></form>
                        </div>
                    </li>
                </template>
            </ol>
        </section>

        <section x-show="eligibilityParticipants.length > 0" class="p-4" aria-label="Peserta cek kelayakan donor">
            <p class="mb-3 text-sm font-black text-violet-700">Cek Kelayakan Donor</p>
            <ol class="space-y-2">
                <template x-for="participant in eligibilityParticipants" :key="participant.id">
                    <li class="rounded-2xl border border-violet-100 p-3" :class="$store.ui.genderCardClass(participant.participant.gender)">
                        <p class="font-mono text-base font-black text-violet-700" x-text="participant.number"></p>
                        <p class="mt-1 truncate font-extrabold text-slate-900" x-text="participant.participant.name"></p>
                        <div class="mt-3 grid gap-2">
                            <p x-show="participant.eligibility.result" class="rounded-xl px-3 py-2 text-xs font-black" :class="participant.eligibility.result === 'eligible' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'" x-text="participant.eligibility.result === 'eligible' ? 'LAYAK DONOR' : 'TIDAK LAYAK DONOR'"></p>
                            <form x-show="participant.can_decide_eligibility" :action="participant.urls.eligible" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-11 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">LAYAK DONOR</button></form>
                            <form x-show="participant.can_decide_eligibility" :action="participant.urls.ineligible" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-11 w-full border-0 bg-slate-700 text-white hover:bg-slate-800">TIDAK LAYAK DONOR</button></form>
                            <form x-show="participant.can_continue_to_health_check" :action="participant.urls.health_check" method="POST" data-realtime-submit data-operation-kind="operational">@csrf<button type="submit" class="btn min-h-11 w-full border-0 bg-cyan-600 text-white hover:bg-cyan-700">LANJUT KE CEK KESEHATAN</button></form>
                        </div>
                    </li>
                </template>
            </ol>
        </section>

        <section x-show="donatingParticipants.length > 0" class="p-4" aria-label="Peserta sedang donor">
            <p class="mb-3 text-sm font-black text-rose-700">SEDANG DONOR</p>
            <ol class="space-y-2">
                <template x-for="participant in donatingParticipants" :key="participant.id">
                    <li class="rounded-2xl border border-rose-100 p-3" :class="$store.ui.genderCardClass(participant.participant.gender)">
                        <p class="font-mono text-base font-black text-rose-700" x-text="participant.number"></p>
                        <p class="mt-1 truncate font-extrabold text-slate-900" x-text="participant.participant.name"></p>
                        <form :action="participant.urls.complete" method="POST" class="mt-3" data-realtime-submit data-operation-kind="operational"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button type="submit" class="btn min-h-11 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button></form>
                    </li>
                </template>
            </ol>
        </section>
    </div>
</section>

<details x-show="finishedParticipants.length > 0" class="overflow-hidden rounded-3xl border border-emerald-100 bg-white shadow-sm">
    <summary class="cursor-pointer list-none px-5 py-4 sm:px-6">
        <span class="flex items-center justify-between gap-3">
            <span><span class="block text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Selesai</span><span class="mt-1 block text-sm font-semibold text-slate-600">Riwayat peserta yang telah menyelesaikan proses.</span></span>
            <span class="rounded-full bg-emerald-100 px-3 py-1.5 text-sm font-black text-emerald-700" x-text="finishedParticipants.length"></span>
        </span>
    </summary>
    <ol class="divide-y divide-slate-100 border-t border-emerald-100">
        <template x-for="participant in finishedParticipants.slice(0, 30)" :key="participant.id">
            <li class="flex items-center gap-3 p-4 opacity-70" :class="$store.ui.genderCardClass(participant.participant.gender)"><span class="font-mono text-base font-black" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span><span class="min-w-0 flex-1"><span class="block truncate font-extrabold text-slate-900" x-text="participant.participant.name"></span><span class="block text-xs font-semibold text-slate-500" x-text="participant.participant.gender_label"></span><span x-show="participant.eligibility.result" class="mt-1 block text-xs font-black" :class="participant.eligibility.result === 'eligible' ? 'text-emerald-700' : 'text-rose-700'" x-text="participant.eligibility.result === 'eligible' ? 'LAYAK DONOR' : 'TIDAK LAYAK DONOR'"></span></span><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-black text-emerald-700">SELESAI</span></li>
        </template>
    </ol>
</details>

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
                    <p class="mt-1 text-sm text-slate-500">Panggil kembali nomor yang masih aktif pada antrean global.</p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm btn-square" @click="closeGoto()" aria-label="Tutup Goto Nomor">×</button>
            </div>

            <form class="mt-6 space-y-4" method="POST" data-realtime-submit @submit="if (!prepareGoto($event)) $event.preventDefault()">
                @csrf
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
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-900" x-text="ticket.participant.name"></span><span class="block truncate text-xs text-slate-500">Urutan #<span x-text="ticket.registration_order"></span> · <span x-text="ticket.participant.gender"></span> · <span x-text="ticket.participant.services.join(' + ')"></span></span></span>
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
