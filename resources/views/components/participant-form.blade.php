@props(['participant' => null])

<div class="grid gap-5 md:grid-cols-2">
    <label class="form-control md:col-span-2">
        <span class="label-text mb-2 font-semibold text-slate-700">Nama lengkap <span class="text-rose-600">*</span></span>
        <input type="text" name="name" value="{{ old('name', $participant?->name) }}" maxlength="255" required autofocus class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none" placeholder="Nama sesuai identitas">
        @error('name')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Nomor HP <span class="text-rose-600">*</span></span>
        <input type="tel" name="phone" value="{{ old('phone', $participant?->phone) }}" maxlength="20" required class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none" placeholder="081234567890">
        @error('phone')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Jenis kelamin <span class="text-rose-600">*</span></span>
        <select name="gender" required class="select select-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none">
            <option value="">Pilih jenis kelamin</option>
            @foreach (\App\Enums\ParticipantGender::cases() as $gender)
                <option value="{{ $gender->value }}" @selected(old('gender', $participant?->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
            @endforeach
        </select>
        @error('gender')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">NIK</span>
        <input type="text" inputmode="numeric" name="nik" value="{{ old('nik', $participant?->nik) }}" maxlength="16" class="input input-bordered w-full rounded-xl border-slate-300 bg-white font-mono focus:border-emerald-500 focus:outline-none" placeholder="16 digit bila tersedia">
        @error('nik')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control">
        <span class="label-text mb-2 font-semibold text-slate-700">Tanggal lahir</span>
        <input type="date" name="birth_date" value="{{ old('birth_date', $participant?->birth_date?->toDateString()) }}" class="input input-bordered w-full rounded-xl border-slate-300 bg-white focus:border-emerald-500 focus:outline-none">
        @error('birth_date')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>

    <label class="form-control md:col-span-2">
        <span class="label-text mb-2 font-semibold text-slate-700">Alamat</span>
        <textarea name="address" rows="4" maxlength="5000" class="textarea textarea-bordered w-full rounded-xl border-slate-300 bg-white leading-relaxed focus:border-emerald-500 focus:outline-none" placeholder="Alamat domisili peserta">{{ old('address', $participant?->address) }}</textarea>
        @error('address')<span class="label-text-alt mt-2 text-rose-600">{{ $message }}</span>@enderror
    </label>
</div>
