@extends('layouts.guest')

@section('title', 'Masuk')

@section('content')
    <main class="grid min-h-screen lg:grid-cols-2">
        <section class="relative hidden overflow-hidden bg-emerald-800 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div class="absolute -right-32 -top-32 size-96 rounded-full bg-emerald-500/20 blur-3xl"></div>
            <div class="absolute -bottom-36 -left-24 size-96 rounded-full bg-teal-300/15 blur-3xl"></div>
            <div class="absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px;"></div>

            <div class="relative flex items-center gap-3">
                <div class="flex size-12 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/20 backdrop-blur">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.35-9.35-9.15C1.12 7.88 3.55 4.5 7.2 4.5c2.04 0 3.38 1.12 4.8 2.8 1.42-1.68 2.76-2.8 4.8-2.8 3.65 0 6.08 3.38 4.55 7.35C19.5 16.65 12 21 12 21Z"/>
                        <path stroke-linecap="round" d="M9 11.5h6M12 8.5v6"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xl font-black tracking-tight">SEHAT-APP</p>
                    <p class="text-sm text-emerald-100">Sistem Pelayanan Panitia</p>
                </div>
            </div>

            <div class="relative max-w-xl">
                <span class="inline-flex rounded-full bg-white/10 px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-emerald-100 ring-1 ring-white/15">
                    Cepat · Realtime · Terintegrasi
                </span>
                <h1 class="mt-7 text-5xl font-black leading-[1.08] tracking-tight">
                    Pelayanan kegiatan yang lebih tertib dan manusiawi.
                </h1>
                <p class="mt-6 max-w-lg text-lg leading-8 text-emerald-100">
                    Satu ruang kerja bagi panitia untuk mengelola alur peserta secara aman, responsif, dan mudah dipahami pada hari kegiatan.
                </p>
            </div>

            <p class="relative text-sm text-emerald-200">
                Akses terbatas untuk panitia yang berwenang.
            </p>
        </section>

        <section class="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-16">
            <div class="w-full max-w-md">
                <div class="mb-10 flex items-center gap-3 lg:hidden">
                    <div class="flex size-11 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-lg shadow-emerald-200">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.35-9.35-9.15C1.12 7.88 3.55 4.5 7.2 4.5c2.04 0 3.38 1.12 4.8 2.8 1.42-1.68 2.76-2.8 4.8-2.8 3.65 0 6.08 3.38 4.55 7.35C19.5 16.65 12 21 12 21Z"/>
                            <path stroke-linecap="round" d="M9 11.5h6M12 8.5v6"/>
                        </svg>
                    </div>
                    <div>
                        <p class="font-black tracking-tight text-slate-900">SEHAT-APP</p>
                        <p class="text-xs font-medium text-emerald-700">Sistem Pelayanan Panitia</p>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-bold text-emerald-700">Selamat datang kembali</p>
                    <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Masuk ke akun Anda</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-500">
                        Gunakan akun panitia yang telah diberikan oleh administrator.
                    </p>
                </div>

                @if (session('status'))
                    <div role="alert" class="alert alert-success mt-7 rounded-2xl text-sm shadow-sm">
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5" novalidate>
                    @csrf

                    <label class="form-control block">
                        <span class="mb-2 block text-sm font-bold text-slate-700">Alamat email</span>
                        <div @class(['input input-bordered flex w-full items-center gap-3 rounded-xl bg-white', 'input-error' => $errors->has('email')])>
                            <svg class="size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 12 13l9-5.5M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Z"/>
                            </svg>
                            <input
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                class="grow"
                                placeholder="nama@contoh.com"
                                autocomplete="username"
                                autofocus
                                required
                            >
                        </div>
                        @error('email')
                            <span class="mt-2 block text-sm font-medium text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="form-control block" x-data="{ showPassword: false }">
                        <span class="mb-2 block text-sm font-bold text-slate-700">Kata sandi</span>
                        <div @class(['input input-bordered flex w-full items-center gap-3 rounded-xl bg-white', 'input-error' => $errors->has('password')])>
                            <svg class="size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect width="16" height="11" x="4" y="10" rx="2"/>
                                <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3"/>
                            </svg>
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                name="password"
                                class="grow"
                                placeholder="Masukkan kata sandi"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" class="btn btn-ghost btn-xs btn-square" @click="showPassword = ! showPassword" :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'">
                                <svg x-show="! showPassword" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                                    <circle cx="12" cy="12" r="2.5"/>
                                </svg>
                                <svg x-cloak x-show="showPassword" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" d="m4 4 16 16"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 6.4A10.8 10.8 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.2 2.8M14.6 14.6A3.7 3.7 0 0 1 9.4 9.4M6.1 7.1C3.8 8.8 2.5 12 2.5 12s3.5 6 9.5 6a10.8 10.8 0 0 0 3.1-.5"/>
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <span class="mt-2 block text-sm font-medium text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex cursor-pointer items-center gap-3 text-sm text-slate-600">
                        <input type="checkbox" name="remember" value="1" class="checkbox checkbox-primary checkbox-sm rounded-md" @checked(old('remember'))>
                        Ingat saya di perangkat ini
                    </label>

                    <button type="submit" class="btn btn-primary h-12 w-full rounded-xl border-0 text-sm font-bold shadow-lg shadow-emerald-200">
                        Masuk ke Dashboard
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m0 0-5-5m5 5-5 5"/>
                        </svg>
                    </button>
                </form>

                <p class="mt-8 text-center text-xs leading-5 text-slate-400">
                    Demi keamanan, jangan membagikan akun panitia kepada pihak yang tidak berwenang.
                </p>
            </div>
        </section>
    </main>
@endsection
