@extends('layouts.operational')

@section('title', 'Operasional')
@section('page-title', 'Operasional')
@section('page-subtitle', 'Panggil dan proses peserta dari satu layar')

@section('content')
    <div
        x-data="waitingQueue(@js($queueJson), @js($dataUrl), {{ $event->id }}, @js($capacityUpdateUrl), @js($canUpdateDonationCapacity))"
        class="mx-auto max-w-6xl space-y-6"
    >
        <section class="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-900 p-6 text-white shadow-xl shadow-slate-200 sm:p-8">
            <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-indigo-200">{{ $event->code }} · Operasional</p>
                    <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">Screen Petugas</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-200 sm:text-base">NEXT dan GOTO memanggil peserta dari antrean. Peserta yang sudah dipanggil tetap terlihat hingga prosesnya selesai atau dilewati.</p>
                </div>
            </div>
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

        @include('operations.partials.waiting-lanes', ['showControls' => true])
    </div>
@endsection
