@extends('layouts.app')

@section('title', 'Screening Donor')
@section('page-title', 'Screening Donor')
@section('page-subtitle', 'Catat keputusan kelayakan dari petugas PMI')

@section('content')
    <div
        x-data="screeningDesk(@js($participantsJson), @js(route('events.screening.data', $event)), {{ $event->id }})"
        class="mx-auto max-w-7xl space-y-6"
    >
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-bold text-emerald-700">{{ $event->code }} · EVENT AKTIF</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Screening Donor</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Panitia hanya mencatat keputusan yang diterima dari PMI. Data medis PMI tidak disimpan di aplikasi.</p>
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
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-amber-600">Menunggu keputusan</p>
                <p class="mt-2 text-3xl font-black text-amber-900" x-text="waitingParticipants.length"></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-600">Layak terbaru</p>
                <p class="mt-2 text-3xl font-black text-emerald-900" x-text="eligibleParticipants.length"></p>
            </div>
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-rose-600">Tidak layak terbaru</p>
                <p class="mt-2 text-3xl font-black text-rose-900" x-text="notEligibleParticipants.length"></p>
            </div>
        </div>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="font-extrabold text-slate-900">Menunggu screening</h2>
                <p class="mt-1 text-sm text-slate-500">Peserta yang memilih donor masuk otomatis setelah registrasi.</p>
            </div>

            <div x-show="waitingParticipants.length === 0" class="px-6 py-16 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3h6v3h4v15H5V6h4V3Z"/><path stroke-linecap="round" d="m8 13 2.5 2.5L16 10"/></svg>
                </div>
                <p class="mt-4 font-bold text-slate-800">Antrean screening kosong</p>
                <p class="mt-1 text-sm text-slate-500">Perubahan dari Pos Pemeriksaan akan muncul tanpa refresh.</p>
            </div>

            <div x-show="waitingParticipants.length > 0" x-cloak class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
                <template x-for="participant in waitingParticipants" :key="participant.id">
                    <article class="rounded-2xl border p-5 transition hover:shadow-md" :class="$store.ui.genderCardClass(participant.participant.gender)">
                        <div class="flex items-start gap-3">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl font-black shadow" :class="$store.ui.genderNumberClass(participant.participant.gender)" x-text="participant.participant.name.charAt(0).toUpperCase()"></span>
                            <div class="min-w-0">
                                <h3 class="truncate font-extrabold text-slate-900" x-text="participant.participant.name"></h3>
                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.participant.gender)">
                                    <span class="mr-1" x-text="$store.ui.genderIcon(participant.participant.gender)"></span>
                                    <span x-text="participant.participant.gender_label"></span>
                                </span>
                            </div>
                        </div>

                        <p class="mt-4 rounded-xl bg-white/70 p-3 text-xs font-semibold leading-5 text-slate-600">Konfirmasi hasil kelayakan yang diberikan petugas PMI.</p>

                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button type="button" @click="choose(participant, 'not_eligible')" class="btn btn-sm border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100">Tidak layak</button>
                            <button type="button" @click="choose(participant, 'eligible')" class="btn btn-sm border-0 bg-emerald-600 text-white hover:bg-emerald-700">Layak donor</button>
                        </div>
                    </article>
                </template>
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="font-extrabold text-slate-900">Keputusan terbaru</h2>
                <p class="mt-1 text-xs text-slate-500">Maksimal 20 keputusan terakhir.</p>
            </div>
            <div x-show="decidedParticipants.length === 0" class="px-6 py-10 text-center text-sm text-slate-500">Belum ada keputusan screening.</div>
            <div x-show="decidedParticipants.length > 0" x-cloak class="overflow-x-auto">
                <table class="table min-w-[700px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Peserta</th><th>Hasil</th><th>Alasan</th><th>Dicatat oleh</th></tr></thead>
                    <tbody>
                        <template x-for="participant in decidedParticipants" :key="participant.id">
                            <tr>
                                <td>
                                    <p class="font-bold text-slate-900" x-text="participant.participant.name"></p>
                                    <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-bold" :class="$store.ui.genderBadgeClass(participant.participant.gender)">
                                        <span class="mr-1" x-text="$store.ui.genderIcon(participant.participant.gender)"></span>
                                        <span x-text="participant.participant.gender_label"></span>
                                    </span>
                                </td>
                                <td><span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="participant.screening.result === 'eligible' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'" x-text="participant.screening.result_label"></span></td>
                                <td class="max-w-xs text-sm text-slate-600" x-text="participant.screening.reason || '—'"></td>
                                <td class="text-sm font-medium text-slate-600" x-text="participant.screening.screened_by"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <div x-show="selected" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm" @keydown.escape.window="closeDecision()">
            <div
                x-ref="decisionDialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="screening-decision-title"
                tabindex="-1"
                @click.outside="closeDecision()"
                class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl outline-none sm:p-7"
            >
                <div class="flex items-start gap-4">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl" :class="decision === 'eligible' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 9.5 17 19 7"/></svg>
                    </span>
                    <div>
                        <h2 id="screening-decision-title" class="text-xl font-extrabold text-slate-900" x-text="decision === 'eligible' ? 'Konfirmasi Layak Donor' : 'Konfirmasi Tidak Layak'"></h2>
                        <p class="mt-1 text-sm text-slate-500"><span class="font-bold" x-text="selected?.participant.name"></span> akan dipindahkan sesuai hasil screening.</p>
                    </div>
                </div>

                <form :action="selected?.urls.store" method="POST" class="mt-6 space-y-5" data-realtime-submit data-operation-kind="screening">
                    @csrf
                    <input type="hidden" name="result" :value="decision">
                    <label x-show="decision === 'not_eligible'" class="form-control">
                        <span class="label-text mb-2 font-bold text-slate-700">Alasan (opsional)</span>
                        <textarea name="reason" x-model="reason" maxlength="500" rows="3" class="textarea textarea-bordered w-full bg-white focus:border-emerald-500 focus:outline-none" placeholder="Contoh: HB rendah, tensi tinggi, sedang minum obat..."></textarea>
                    </label>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeDecision()" class="btn btn-ghost">Batal</button>
                        <button type="submit" class="btn border-0 text-white" :class="decision === 'eligible' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'">Simpan keputusan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
