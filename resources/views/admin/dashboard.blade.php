@extends('layouts.app')

@section('title', 'Administrasi')
@section('page-title', 'Administrasi')
@section('page-subtitle', 'Ringkasan pengelolaan sistem dan event')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        <section class="rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-emerald-900 p-6 text-white shadow-xl shadow-slate-300/70 sm:p-8">
            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-emerald-200">Administration</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">Kelola event dan akses panitia</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-200">Area ini terpisah dari ruang kerja operasional. Gunakan untuk konfigurasi event, master peserta, akses pengguna, dan audit sistem.</p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Ringkasan administrasi">
            @foreach ([
                ['Event', $metrics['events'], 'bg-emerald-50 text-emerald-700'],
                ['Event aktif', $metrics['active_events'], 'bg-sky-50 text-sky-700'],
                ['Peserta master', $metrics['participants'], 'bg-violet-50 text-violet-700'],
                ['Pengguna', $metrics['users'], 'bg-amber-50 text-amber-700'],
                ['Audit log', $metrics['audit_logs'], 'bg-slate-100 text-slate-700'],
            ] as [$label, $value, $class])
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold text-slate-500">{{ $label }}</p>
                    <p class="mt-2 text-3xl font-black {{ $class }}">{{ number_format($value) }}</p>
                </article>
            @endforeach
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            @can('events.manage')
                <a href="{{ route('events.index') }}" class="group rounded-3xl border border-emerald-100 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-lg">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-emerald-700">Event</p>
                    <h2 class="mt-2 text-lg font-extrabold text-slate-900">Event & Pengaturan Event</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Atur detail event, nomor antrean, kapasitas bed donor, dan Reset Antrean Event.</p>
                    <span class="mt-5 inline-flex text-sm font-bold text-emerald-700 group-hover:text-emerald-800">Buka Event <span class="ml-1" aria-hidden="true">→</span></span>
                </a>
            @endcan
            @can('users.manage')
                <a href="{{ route('admin.users.index') }}" class="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-600">Akses</p>
                    <h2 class="mt-2 text-lg font-extrabold text-slate-900">User & Permission</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Lihat akun aktif, role, permission langsung, dan permission yang diwariskan dari role.</p>
                    <span class="mt-5 inline-flex text-sm font-bold text-slate-700 group-hover:text-slate-900">Buka akses pengguna <span class="ml-1" aria-hidden="true">→</span></span>
                </a>
            @endcan
            @can('audit-logs.view')
                <a href="{{ route('admin.audit-logs.index') }}" class="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-600">Kepatuhan</p>
                    <h2 class="mt-2 text-lg font-extrabold text-slate-900">Audit Log</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Telusuri aktivitas administratif dan operasional, termasuk tindakan Reset Antrean Event.</p>
                    <span class="mt-5 inline-flex text-sm font-bold text-slate-700 group-hover:text-slate-900">Buka audit log <span class="ml-1" aria-hidden="true">→</span></span>
                </a>
            @endcan
        </section>
    </div>
@endsection
