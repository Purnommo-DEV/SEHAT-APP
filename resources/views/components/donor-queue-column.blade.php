@props(['title', 'subtitle', 'queueType', 'accent' => 'emerald'])

<section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
    <div @class([
        'border-b px-5 py-4 sm:px-6',
        'border-sky-100 bg-sky-50/70' => $accent === 'sky',
        'border-fuchsia-100 bg-fuchsia-50/70' => $accent === 'fuchsia',
    ])>
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-extrabold text-slate-900">{{ $title }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $subtitle }}</p>
            </div>
            <span @class([
                'rounded-full px-3 py-1.5 text-sm font-black',
                'bg-sky-100 text-sky-700' => $accent === 'sky',
                'bg-fuchsia-100 text-fuchsia-700' => $accent === 'fuchsia',
            ]) x-text="ticketsFor(@js($queueType)).length"></span>
        </div>
    </div>

    <div x-show="ticketsFor(@js($queueType)).length === 0" class="px-6 py-14 text-center">
        <p class="font-bold text-slate-700">Antrean kosong</p>
        <p class="mt-1 text-sm text-slate-500">Nomor diterbitkan saat peserta masuk proses donor.</p>
    </div>

    <ol x-show="ticketsFor(@js($queueType)).length > 0" x-cloak class="divide-y divide-slate-100">
        <template x-for="ticket in ticketsFor(@js($queueType))" :key="ticket.id">
            <li class="flex flex-col gap-4 border-l-4 px-5 py-5 sm:flex-row sm:items-center sm:px-6" :class="$store.ui.genderCardClass(ticket.participant.gender)">
                <div class="flex min-w-0 flex-1 items-center gap-4">
                    <span
                        class="flex size-16 shrink-0 items-center justify-center rounded-2xl font-mono text-2xl font-black shadow-lg"
                        :class="$store.ui.genderNumberClass(ticket.participant.gender)"
                        x-text="ticket.formatted_number"
                    ></span>
                    <div class="min-w-0">
                        <p class="truncate font-extrabold text-slate-900" x-text="ticket.participant.name"></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(ticket.participant.gender)">
                                <span class="mr-1" x-text="$store.ui.genderIcon(ticket.participant.gender)"></span>
                                <span x-text="ticket.participant.gender_label"></span>
                            </span>
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="statusClass(ticket.status)" x-text="ticket.status_label"></span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 sm:justify-end">
                    <template x-if="['waiting', 'skipped'].includes(ticket.status)">
                        <form :action="ticket.urls.call" method="POST" data-realtime-submit data-operation-kind="donation">
                            @csrf
                            <button type="submit" class="btn btn-sm border-0 bg-amber-500 text-white hover:bg-amber-600">Panggil</button>
                        </form>
                    </template>
                    <template x-if="ticket.status === 'calling'">
                        <div class="flex gap-2">
                            <form :action="ticket.urls.start" method="POST" data-realtime-submit data-operation-kind="donation">
                                @csrf
                                <button type="submit" class="btn btn-sm border-0 bg-emerald-600 text-white hover:bg-emerald-700">Mulai donor</button>
                            </form>
                            <form :action="ticket.urls.skip" method="POST" data-realtime-submit data-operation-kind="donation" :data-confirm-title="'Lewati nomor ' + ticket.formatted_number + '?'" data-confirm-message="Nomor dapat dipanggil kembali.">
                                @csrf
                                <button type="submit" class="btn btn-ghost btn-sm">Lewati</button>
                            </form>
                        </div>
                    </template>
                    <template x-if="ticket.status === 'serving'">
                        <form :action="ticket.urls.complete" method="POST" data-realtime-submit data-operation-kind="donation" :data-confirm-title="'Donor ' + ticket.formatted_number + ' selesai?'" data-confirm-message="Proses donor ditandai selesai. Peserta yang memilih pemeriksaan kesehatan akan otomatis masuk antrean pemeriksaan.">
                            @csrf
                            <button type="submit" class="btn btn-sm border-0 bg-emerald-600 text-white hover:bg-emerald-700">Donor selesai</button>
                        </form>
                    </template>
                    <template x-if="['waiting', 'calling', 'skipped'].includes(ticket.status)">
                        <form :action="ticket.urls.cancel" method="POST" data-realtime-submit data-operation-kind="donation" :data-confirm-title="'Batalkan nomor ' + ticket.formatted_number + '?'" data-confirm-message="Nomor dilepas dan dapat digunakan kembali." data-confirm-variant="warning">
                            @csrf
                            <button type="submit" class="btn btn-ghost btn-sm text-rose-700">Batalkan</button>
                        </form>
                    </template>
                </div>
            </li>
        </template>
    </ol>
</section>
