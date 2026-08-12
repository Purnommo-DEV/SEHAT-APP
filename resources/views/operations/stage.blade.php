@extends('layouts.operational')

@section('title', $title)
@section('page-title', $title)
@section('page-subtitle', $subtitle)

@section('content')
    <div
        x-data="operationalStage(@js($participantsJson), @js($dataUrl), {{ $event->id }}, @js($donationCapacity), @js($capacityUpdateUrl), @js($canUpdateDonationCapacity))"
        class="mx-auto max-w-4xl space-y-6"
    >
        <section class="rounded-3xl bg-gradient-to-br from-emerald-700 via-emerald-600 to-teal-600 p-6 text-white shadow-xl shadow-emerald-200 sm:p-8">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-100">{{ $event->code }} · Area Kerja</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">{{ $title }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-emerald-50 sm:text-base">{{ $description }}</p>
            @if ($queueDeskUrl)
                <a href="{{ $queueDeskUrl }}" class="btn mt-5 min-h-12 border-0 bg-white text-emerald-800 hover:bg-emerald-50">BUKA SCREEN PETUGAS</a>
            @endif
        </section>

        <section class="rounded-3xl border border-rose-100 bg-white p-5 shadow-sm sm:p-6" aria-label="Kapasitas donor per gender">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-600">Kapasitas Donor per Gender</p>
                    <p class="mt-1 text-sm font-semibold text-slate-500">Slot laki-laki dan perempuan berjalan mandiri.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-700">
                    Total <span x-text="capacity?.total?.active ?? 0"></span> / <span x-text="capacity?.total?.capacity ?? 0"></span>
                </span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <template x-for="gender in capacityGenders" :key="gender.key">
                    <article class="rounded-2xl border p-4" :class="gender.key === 'male' ? 'border-indigo-100 bg-indigo-50/60' : 'border-rose-100 bg-rose-50/60'">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-extrabold text-slate-900" x-text="capacityFor(gender.key).label"></p>
                            <span class="rounded-full px-2.5 py-1 text-xs font-black" :class="capacityFor(gender.key).is_full ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'" x-text="capacityFor(gender.key).is_full ? 'PENUH' : 'TERSEDIA'"></span>
                        </div>
                        <p class="mt-3 text-2xl font-black text-slate-900"><span x-text="capacityFor(gender.key).active"></span> / <span x-text="capacityFor(gender.key).capacity"></span></p>
                        <p class="mt-1 text-sm font-semibold text-slate-600"><span x-text="capacityFor(gender.key).available"></span> slot tersedia</p>
                    </article>
                </template>
            </div>

            @if ($canUpdateDonationCapacity)
                <form action="{{ $capacityUpdateUrl }}" method="POST" class="mt-5 border-t border-slate-100 pt-5" data-realtime-submit data-operation-kind="donation-capacity">
                    @csrf
                    @method('PATCH')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="form-control">
                            <span class="label-text mb-2 font-bold text-slate-700">Kapasitas Laki-laki</span>
                            <div class="join w-full">
                                <button type="button" class="btn join-item min-h-11 min-w-11 border-slate-300 bg-white" @click="adjustCapacity('male', -1)" aria-label="Kurangi kapasitas laki-laki">-</button>
                                <input name="donation_capacity_male" type="number" min="1" max="50" required class="input join-item min-h-11 w-full border-slate-300 bg-white text-center font-black" x-model.number="editableCapacity.male">
                                <button type="button" class="btn join-item min-h-11 min-w-11 border-slate-300 bg-white" @click="adjustCapacity('male', 1)" aria-label="Tambah kapasitas laki-laki">+</button>
                            </div>
                        </label>
                        <label class="form-control">
                            <span class="label-text mb-2 font-bold text-slate-700">Kapasitas Perempuan</span>
                            <div class="join w-full">
                                <button type="button" class="btn join-item min-h-11 min-w-11 border-slate-300 bg-white" @click="adjustCapacity('female', -1)" aria-label="Kurangi kapasitas perempuan">-</button>
                                <input name="donation_capacity_female" type="number" min="1" max="50" required class="input join-item min-h-11 w-full border-slate-300 bg-white text-center font-black" x-model.number="editableCapacity.female">
                                <button type="button" class="btn join-item min-h-11 min-w-11 border-slate-300 bg-white" @click="adjustCapacity('female', 1)" aria-label="Tambah kapasitas perempuan">+</button>
                            </div>
                        </label>
                    </div>
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs font-semibold text-slate-500">Perubahan dicatat dan langsung diperbarui ke seluruh layar.</p>
                        <button type="submit" class="btn min-h-11 border-0 bg-emerald-600 text-white hover:bg-emerald-700">SIMPAN KAPASITAS</button>
                    </div>
                </form>
            @else
                <p class="mt-4 border-t border-slate-100 pt-4 text-xs font-semibold text-slate-500">Kapasitas hanya dapat diubah oleh petugas yang memiliki izin khusus.</p>
            @endif
        </section>

        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ $errors->first() }}</div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-label="{{ $title }}">
            <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Daftar Peserta</p>
                    <p class="mt-1 text-sm text-slate-600">Nomor antrean terkecil selalu ditampilkan paling atas.</p>
                </div>
                <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-black text-emerald-700" x-text="participants.length"></span>
            </header>

            <div x-show="participants.length === 0" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada peserta pada area ini.</div>

            @if ($action === 'complete-donation')
                <div x-show="participants.length > 0" class="grid gap-5 p-4 sm:grid-cols-2 sm:p-5">
                    <template x-for="gender in capacityGenders" :key="gender.key">
                        <section class="overflow-hidden rounded-2xl border" :class="gender.key === 'male' ? 'border-indigo-100' : 'border-rose-100'">
                            <header class="flex items-center justify-between px-4 py-3" :class="gender.key === 'male' ? 'bg-indigo-50 text-indigo-900' : 'bg-rose-50 text-rose-900'">
                                <span class="font-extrabold" x-text="capacityFor(gender.key).label"></span>
                                <span class="rounded-full bg-white/80 px-2.5 py-1 text-xs font-black" x-text="`${participantsForGender(gender.key).length} peserta`"></span>
                            </header>
                            <ol class="divide-y divide-slate-100">
                                <template x-for="participant in participantsForGender(gender.key)" :key="participant.id">
                                    <li class="p-4" :class="$store.ui.genderCardClass(participant.participant.gender)">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                                            <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl font-mono text-lg font-black shadow-lg" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span>
                                            <div class="min-w-0 flex-1">
                                                <h2 class="truncate text-base font-extrabold text-slate-900" x-text="participant.participant.name"></h2>
                                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.participant.gender)" x-text="participant.participant.gender_label"></span>
                                                <p class="mt-3 text-xs font-bold uppercase tracking-wide text-slate-500" x-text="participant.status_label"></p>
                                            </div>
                                            <form :action="participant.urls.complete" method="POST" data-realtime-submit data-operation-kind="operational">
                                                @csrf
                                                <button type="submit" class="btn min-h-12 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto">SELESAI</button>
                                            </form>
                                        </div>
                                    </li>
                                </template>
                            </ol>
                        </section>
                    </template>
                </div>
            @else
                <ol x-show="participants.length > 0" class="divide-y divide-slate-100">
                    <template x-for="participant in participants" :key="participant.id">
                        <li class="p-4 sm:p-5" :class="$store.ui.genderCardClass(participant.participant.gender)">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl font-mono text-lg font-black shadow-lg" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.number"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="truncate text-base font-extrabold text-slate-900" x-text="participant.participant.name"></h2>
                                        <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.participant.gender)">
                                            <span x-text="$store.ui.genderIcon(participant.participant.gender)"></span>
                                            <span x-text="participant.participant.gender_label"></span>
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">Layanan dipilih</p>
                                    <p class="mt-0.5 text-sm font-medium text-slate-600" x-text="participant.services.map(service => service.label).join(' + ')"></p>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Posisi saat ini</span>
                                        <span class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-black text-white" x-text="participant.position?.label ?? participant.status_label"></span>
                                    </div>
                                    <p x-show="participant.completed_at" class="mt-1 text-xs font-semibold text-slate-500">Selesai <span x-text="formatDate(participant.completed_at)"></span></p>
                                </div>

                                @if ($action !== 'read-only')
                                    <div class="grid shrink-0 gap-2 sm:min-w-44">
                                        @if ($action === 'start-health-check')
                                            <form :action="participant.urls.health_check" method="POST" data-realtime-submit data-operation-kind="operational">
                                                @csrf
                                                <button type="submit" class="btn min-h-12 w-full border-0 bg-cyan-600 text-white hover:bg-cyan-700">CEK KESEHATAN</button>
                                            </form>
                                        @elseif ($action === 'before-donation')
                                            <form x-show="participant.can_donate" :action="participant.urls.donate" method="POST" data-realtime-submit data-operation-kind="operational">
                                                <p class="mb-2 text-center text-xs font-bold text-slate-600" x-text="`Kapasitas ${capacityFor(participant.participant.gender).label}: ${capacityFor(participant.participant.gender).active} / ${capacityFor(participant.participant.gender).capacity}`"></p>
                                                <p x-show="capacityFor(participant.participant.gender).is_full" class="mb-2 text-center text-xs font-bold text-rose-700">Bed penuh - menunggu slot donor</p>
                                                @csrf
                                                <button type="submit" :disabled="capacityFor(participant.participant.gender).is_full" class="btn min-h-12 w-full border-0 bg-rose-600 text-white hover:bg-rose-700 disabled:bg-slate-300 disabled:text-slate-600">DONOR</button>
                                            </form>
                                            <form x-show="participant.can_complete_before_donation" :action="participant.urls.complete_before_donation" method="POST" data-realtime-submit data-operation-kind="operational">
                                                @csrf
                                                <button type="submit" class="btn min-h-12 w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">SELESAI</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </li>
                    </template>
                </ol>
            @endif
        </section>
    </div>
@endsection
