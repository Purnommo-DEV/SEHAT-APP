@props([
    'servicePost' => null,
    'operators' => collect(),
    'activeRepair' => false,
    'behaviors' => \App\Enums\ServicePostBehavior::cases(),
])

@php
    $selectedOperators = collect(old('operator_ids', $servicePost?->operators?->modelKeys() ?? []))->map(fn ($id) => (int) $id);
@endphp

<div class="grid gap-5 md:grid-cols-2">
    <label class="form-control md:col-span-2">
        <span class="label-text mb-2 font-semibold text-slate-700">Nama pos <span class="text-rose-600">*</span></span>
        <input type="text" name="name" value="{{ old('name', $servicePost?->name) }}" maxlength="255" required autofocus class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none" placeholder="Contoh: Pemeriksaan 1">
        @error('name')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Kode pos <span class="text-rose-600">*</span></span>
        <input type="text" name="code" value="{{ old('code', $servicePost?->code) }}" maxlength="30" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono lowercase tracking-wide focus:border-emerald-500 focus:outline-none" placeholder="pemeriksaan-1">
        <span class="label-text-alt mt-2 text-slate-500">Huruf kecil, angka, dan tanda hubung.</span>
        @error('code')<span class="label-text-alt mt-1 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Cara pelayanan <span class="text-rose-600">*</span></span>
        <select name="behavior" required class="select select-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none">
            @foreach ($behaviors as $behavior)
                <option value="{{ $behavior->value }}" @selected(old('behavior', $servicePost?->behavior?->value ?? \App\Enums\ServicePostBehavior::ConfirmationOnly->value) === $behavior->value)>{{ $behavior->label() }}</option>
            @endforeach
        </select>
        <span class="label-text-alt mt-2 text-slate-500">Kelayakan, donor, dan kesehatan menjadi tujuan routing berdasarkan layanan peserta.</span>
        @error('behavior')<span class="label-text-alt mt-1 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Awalan nomor antrean</span>
        <input type="text" name="queue_prefix" value="{{ old('queue_prefix', $servicePost?->queue_prefix) }}" maxlength="10" class="input input-bordered w-full rounded-xl border-slate-300 bg-white uppercase focus:border-emerald-500 focus:outline-none" placeholder="Contoh: A">
        @error('queue_prefix')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Jumlah digit nomor <span class="text-rose-600">*</span></span>
        <input type="number" name="queue_number_digits" min="1" max="6" value="{{ old('queue_number_digits', $servicePost?->queue_number_digits ?? 3) }}" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none">
        @error('queue_number_digits')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <fieldset class="rounded-2xl border border-slate-200 p-4 md:col-span-2">
        <legend class="px-2 text-sm font-bold text-slate-700">Petugas pos</legend>
        <p class="mb-3 text-xs text-slate-500">Petugas terpilih akan melihat pos ini pada menu Antrean Saya.</p>
        <div class="grid max-h-52 gap-2 overflow-y-auto sm:grid-cols-2">
            @forelse ($operators as $operator)
                <label class="flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm">
                    <input type="checkbox" name="operator_ids[]" value="{{ $operator->id }}" class="checkbox checkbox-success checkbox-sm" @checked($selectedOperators->contains($operator->id))>
                    <span class="min-w-0"><span class="block truncate font-bold text-slate-800">{{ $operator->name }}</span><span class="block truncate text-xs text-slate-500">{{ $operator->email }}</span></span>
                </label>
            @empty
                <p class="text-sm text-slate-500">Belum ada pengguna aktif.</p>
            @endforelse
        </div>
        @error('operator_ids.*')<span class="mt-2 block text-xs text-rose-600">{{ $message }}</span>@enderror
    </fieldset>

    @if ($activeRepair)
        <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3.5 text-sm text-emerald-900 md:col-span-2">
            <input type="hidden" name="is_active" value="1">
            <span class="flex size-5 items-center justify-center rounded-full bg-emerald-600 text-white">✓</span>
            <span><span class="block font-bold">Pos langsung aktif</span><span class="mt-0.5 block text-xs text-emerald-800">Melengkapi layanan SOP pada event aktif tanpa mengubah pos dan histori yang sudah ada.</span></span>
        </div>
    @else
        <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm text-slate-700 md:col-span-2">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="toggle toggle-success" @checked(old('is_active', $servicePost?->is_active ?? true))>
            <span><span class="block font-bold">Pos aktif</span><span class="mt-0.5 block text-xs text-slate-500">Sediakan minimal satu pos aktif untuk kelayakan, donor, dan pemeriksaan kesehatan.</span></span>
        </label>
    @endif

    <label class="form-control md:col-span-2">
        <span class="label-text mb-2 font-semibold text-slate-700">Keterangan</span>
        <textarea name="description" rows="4" maxlength="5000" class="textarea textarea-bordered w-full rounded-xl border-slate-300 bg-white leading-relaxed focus:border-emerald-500 focus:outline-none" placeholder="Tujuan atau instruksi kerja pada pos ini">{{ old('description', $servicePost?->description) }}</textarea>
        @error('description')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>
</div>
