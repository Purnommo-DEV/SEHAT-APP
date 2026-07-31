@props(['event' => null])

<div class="grid gap-5 md:grid-cols-2">
    <label class="form-control md:col-span-2">
        <span class="label-text mb-2 font-semibold text-slate-700">Nama event <span class="text-rose-600">*</span></span>
        <input
            type="text"
            name="name"
            value="{{ old('name', $event?->name) }}"
            maxlength="255"
            required
            autofocus
            class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none"
            placeholder="Contoh: Donor Darah Muharram 1448 H"
        >
        @error('name')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Kode event <span class="text-rose-600">*</span></span>
        <input
            type="text"
            name="code"
            value="{{ old('code', $event?->code) }}"
            maxlength="30"
            required
            class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono uppercase tracking-wide focus:border-emerald-500 focus:outline-none"
            placeholder="DONOR-2026-01"
        >
        <span class="label-text-alt mt-2 text-slate-500">Huruf kapital, angka, dan tanda hubung.</span>
        @error('code')<span class="label-text-alt mt-1 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Lokasi</span>
        <input
            type="text"
            name="location"
            value="{{ old('location', $event?->location) }}"
            maxlength="255"
            class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none"
            placeholder="Masjid atau lokasi kegiatan"
        >
        @error('location')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Mulai <span class="text-rose-600">*</span></span>
        <input
            type="datetime-local"
            name="starts_at"
            value="{{ old('starts_at', $event?->starts_at?->format('Y-m-d\\TH:i')) }}"
            required
            class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none"
        >
        @error('starts_at')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Selesai</span>
        <input
            type="datetime-local"
            name="ends_at"
            value="{{ old('ends_at', $event?->ends_at?->format('Y-m-d\\TH:i')) }}"
            class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none"
        >
        @error('ends_at')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control md:col-span-2">
        <span class="label-text mb-2 font-semibold text-slate-700">Keterangan</span>
        <textarea
            name="description"
            rows="4"
            maxlength="5000"
            class="textarea textarea-bordered w-full rounded-xl border-slate-300 bg-white leading-relaxed focus:border-emerald-500 focus:outline-none"
            placeholder="Tujuan atau catatan operasional event"
        >{{ old('description', $event?->description) }}</textarea>
        @error('description')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>
</div>
