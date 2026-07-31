@extends('layouts.app')

@section('title', $event->name)
@section('page-title', 'Detail Event')
@section('page-subtitle', 'Konteks operasional dan pengaturan antrean')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div class="flex items-start gap-3">
                <a href="{{ route('events.index') }}" class="btn btn-ghost btn-sm btn-square mt-0.5" aria-label="Kembali ke daftar event">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                </a>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $event->name }}</h1>
                        <x-event-status-badge :status="$event->status" />
                    </div>
                    <p class="mt-2 font-mono text-sm font-semibold text-emerald-700">{{ $event->code }}</p>
                </div>
            </div>

            @if ($event->status->value === 'draft' || $canManageServicePosts)
                <div class="flex flex-wrap gap-2">
                    @if ($canManageServicePosts)
                        <a href="#jalur-pelayanan" class="btn btn-outline border-slate-300 text-slate-700 hover:border-emerald-600 hover:bg-emerald-50">Kelola Pos Pelayanan</a>
                        @if ($canCreateServicePost)
                            <a href="{{ route('events.service-posts.create', $event) }}" class="btn border-0 bg-emerald-600 text-white hover:bg-emerald-700">Tambah Pos Pelayanan</a>
                        @endif
                    @endif
                    @if ($event->status->value === 'draft')
                        <a href="{{ route('events.edit', $event) }}" class="btn btn-outline border-slate-300 text-slate-700 hover:border-emerald-600 hover:bg-emerald-50">Ubah event</a>
                        <a href="{{ route('events.settings.edit', $event) }}" class="btn btn-outline border-slate-300 text-slate-700 hover:border-emerald-600 hover:bg-emerald-50">Pengaturan Nomor</a>
                    @endif
                </div>
            @endif
        </div>

        <nav aria-label="Bagian event" class="flex gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
            <a href="#informasi-event" class="btn btn-sm btn-ghost shrink-0">Informasi</a>
            @can('participants.manage')
                <a href="{{ route('participants.index') }}" class="btn btn-sm btn-ghost shrink-0">Peserta</a>
            @endcan
            @if ($canManageServicePosts)
                <a href="#jalur-pelayanan" class="btn btn-sm btn-ghost shrink-0">Jalur Pelayanan</a>
            @endif
            @can('dashboard.view')
                <a href="{{ route('dashboard') }}" class="btn btn-sm btn-ghost shrink-0">Dashboard</a>
            @endcan
            @can('reports.view')
                <a href="{{ route('reports.index', ['event_id' => $event->id]) }}" class="btn btn-sm btn-ghost shrink-0">Laporan</a>
            @endcan
        </nav>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.85fr)]">
            <section id="informasi-event" class="scroll-mt-24 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-11 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3v3m8-3v3M4.5 9.5h15M6.5 5h11A1.5 1.5 0 0 1 19 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 17.5v-11A1.5 1.5 0 0 1 6.5 5Z"/></svg>
                    </div>
                    <div>
                        <h2 class="font-bold text-slate-900">Informasi kegiatan</h2>
                        <p class="text-sm text-slate-500">Identitas yang akan mengikuti seluruh data operasional.</p>
                    </div>
                </div>

                <dl class="mt-7 grid gap-6 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Mulai</dt>
                        <dd class="mt-2 font-semibold text-slate-800">{{ $event->starts_at->translatedFormat('l, d F Y · H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Selesai</dt>
                        <dd class="mt-2 font-semibold text-slate-800">{{ $event->ends_at?->translatedFormat('l, d F Y · H:i') ?? 'Belum ditentukan' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Lokasi</dt>
                        <dd class="mt-2 font-semibold text-slate-800">{{ $event->location ?? 'Belum ditentukan' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Dibuat oleh</dt>
                        <dd class="mt-2 font-semibold text-slate-800">{{ $event->creator->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Mode nomor donor</dt>
                        <dd class="mt-2 font-semibold text-slate-800">{{ $event->settings->donor_number_mode->label() }}</dd>
                    </div>
                </dl>

                @if ($event->description)
                    <div class="mt-7 border-t border-slate-100 pt-6">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Keterangan</p>
                        <p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-600">{{ $event->description }}</p>
                    </div>
                @endif
            </section>

            <aside class="space-y-6">
                @if ($canManageServicePosts)
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Pos pelayanan</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $event->service_posts_count }} pos dalam urutan layanan.</p>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">{{ $event->service_posts_count }} pos</span>
                        </div>
                        @if ($canBootstrapWorkflow)
                            <p class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">Event aktif ini belum memiliki Jalur Pelayanan. Tambahkan pos pertama sebelum peserta check-in.</p>
                        @endif
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <a href="#jalur-pelayanan" class="btn btn-outline border-slate-300 text-slate-700 hover:border-emerald-600 hover:bg-emerald-50">Kelola Pos Pelayanan</a>
                            @if ($canCreateServicePost)
                                <a href="{{ route('events.service-posts.create', $event) }}" class="btn border-0 bg-emerald-600 text-white hover:bg-emerald-700">Tambah Pos Pelayanan</a>
                            @endif
                        </div>
                    </section>
                @endif

                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Status operasional</p>
                    <div class="mt-4">
                        @if ($event->status->value === 'draft')
                            <p class="text-sm leading-6 text-slate-600">Event masih aman untuk diperbarui. Aktifkan saat seluruh konfigurasi siap digunakan di hari H.</p>
                            <form action="{{ route('events.activate', $event) }}" method="POST" class="mt-5" data-confirm-title="Aktifkan {{ $event->name }}?" data-confirm-message="Event aktif menjadi konteks operasional seluruh panitia.">
                                @csrf
                                <button type="submit" class="btn w-full border-0 bg-emerald-600 text-white hover:bg-emerald-700">Aktifkan event</button>
                            </form>
                        @elseif ($event->status->value === 'active')
                            <p class="text-sm leading-6 text-slate-600">Event sedang menjadi konteks operasional aktif. Selesaikan setelah seluruh alur pelayanan berakhir.</p>
                            <form action="{{ route('events.complete', $event) }}" method="POST" class="mt-5" data-confirm-title="Selesaikan {{ $event->name }}?" data-confirm-message="Event selesai tidak dapat diaktifkan kembali." data-confirm-variant="question">
                                @csrf
                                <button type="submit" class="btn w-full border-sky-200 bg-sky-50 text-sky-700 hover:border-sky-300 hover:bg-sky-100">Selesaikan event</button>
                            </form>
                        @elseif ($event->status->value === 'completed')
                            <p class="text-sm leading-6 text-slate-600">Event telah selesai pada {{ $event->ended_at?->translatedFormat('d F Y H:i') }}.</p>
                        @else
                            <p class="text-sm leading-6 text-slate-600">Event ini dibatalkan dan tidak dapat digunakan untuk alur operasional.</p>
                        @endif
                    </div>

                    @if (in_array($event->status->value, ['draft', 'active'], true))
                        <form action="{{ route('events.cancel', $event) }}" method="POST" class="mt-3" data-confirm-title="Batalkan {{ $event->name }}?" data-confirm-message="Status event akan berubah menjadi dibatalkan." data-confirm-variant="warning">
                            @csrf
                            <button type="submit" class="btn btn-ghost w-full text-rose-600 hover:bg-rose-50">Batalkan event</button>
                        </form>
                    @endif
                </section>

            </aside>
        </div>

        @if ($canManageServicePosts)
            <section id="jalur-pelayanan" class="scroll-mt-24 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col justify-between gap-4 border-b border-slate-100 p-6 sm:flex-row sm:items-center sm:p-8">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Workflow Builder</p>
                        <h2 class="mt-2 text-2xl font-black text-slate-900">Jalur Pelayanan</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Routing mengikuti layanan yang dipilih: kelayakan donor, donor jika layak, lalu pemeriksaan kesehatan bila dipilih.</p>
                    </div>
                    @if ($canCreateServicePost)
                        <a href="{{ route('events.service-posts.create', $event) }}" class="btn border-0 bg-emerald-600 text-white hover:bg-emerald-700">Tambah Pos</a>
                    @endif
                </div>

                @if (! $workflowValidation->valid)
                    <div class="border-b border-amber-200 bg-amber-50 px-6 py-4 text-sm text-amber-900 sm:px-8">
                        <p class="font-extrabold">{{ $event->status->value === 'active' ? 'Workflow event aktif belum lengkap' : 'Workflow belum siap diaktifkan' }}</p>
                        @if ($event->status->value === 'active' && $workflowValidation->hasAvailableService())
                            <p class="mt-1">Layanan yang sudah siap tetap dapat digunakan. Tambahkan pos yang hilang untuk membuka layanan lainnya.</p>
                        @endif
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach ($workflowValidation->errors as $workflowError)
                                <li>{{ $workflowError }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($event->servicePosts->isEmpty())
                    <div class="p-10 text-center">
                        <p class="font-bold text-slate-800">Jalur pelayanan belum disusun</p>
                        <p class="mt-1 text-sm text-slate-500">Tambahkan pos pertama agar event dapat diaktifkan dan Check-In siap digunakan.</p>
                    </div>
                @else
                    <ol class="divide-y divide-slate-100">
                        @foreach ($event->servicePosts as $post)
                            <li class="flex flex-col gap-4 p-5 sm:p-6 lg:flex-row lg:items-center">
                                <div class="flex min-w-0 flex-1 items-start gap-4">
                                    <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-slate-100 font-mono font-black text-slate-700">{{ str_pad((string) $post->sequence, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-extrabold text-slate-900">{{ $post->name }}</h3>
                                            <span class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">{{ $post->behavior->label() }}</span>
                                            <span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-50 text-emerald-700' => $post->is_active, 'bg-slate-100 text-slate-500' => ! $post->is_active])>{{ $post->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                        </div>
                                        <p class="mt-2 text-sm text-slate-500">{{ $post->description ?: $post->behavior->description() }}</p>
                                        <p class="mt-2 text-xs font-semibold text-slate-400">Petugas: {{ $post->operators->pluck('name')->join(', ') ?: 'Belum ditugaskan' }}</p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($event->status->value === 'draft')
                                        <form action="{{ route('events.service-posts.move', [$event, $post]) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="direction" value="up">
                                            <button class="btn btn-sm" @disabled($loop->first) aria-label="Naikkan {{ $post->name }}">↑</button>
                                        </form>
                                        <form action="{{ route('events.service-posts.move', [$event, $post]) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="direction" value="down">
                                            <button class="btn btn-sm" @disabled($loop->last) aria-label="Turunkan {{ $post->name }}">↓</button>
                                        </form>
                                        <a href="{{ route('events.service-posts.edit', [$event, $post]) }}" class="btn btn-ghost btn-sm">Ubah</a>
                                        <form action="{{ route('events.service-posts.destroy', [$event, $post]) }}" method="POST" data-confirm-title="Hapus {{ $post->name }}?" data-confirm-message="Pos akan dihapus dari Jalur Pelayanan." data-confirm-variant="warning">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button>
                                        </form>
                                    @endif
                                    @if (in_array($event->status->value, ['draft', 'active'], true))
                                        <form action="{{ route('events.service-posts.toggle', [$event, $post]) }}" method="POST">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm">{{ $post->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        </form>
                                    @endif
                                    @can('queues.manage')
                                        @if ($post->is_active)
                                            <a href="{{ route('events.service-queues.index', [$event, $post]) }}" class="btn btn-sm border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100">Buka antrean</a>
                                        @endif
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-bold text-slate-900">Aktivitas event</h2>
                    <p class="mt-1 text-sm text-slate-500">Jejak audit perubahan penting pada event ini.</p>
                </div>
            </div>

            <ol class="mt-6 space-y-5">
                @forelse ($event->auditLogs as $log)
                    <li class="flex gap-4">
                        <div class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-xs font-extrabold text-emerald-700">{{ mb_strtoupper(mb_substr($log->user?->name ?? 'S', 0, 1)) }}</div>
                        <div class="min-w-0 flex-1 border-b border-slate-100 pb-5">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <p class="font-semibold text-slate-800">{{ $log->action->label() }}</p>
                                <time class="text-xs font-medium text-slate-400">{{ $log->created_at->translatedFormat('d M Y · H:i') }}</time>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">oleh {{ $log->user?->name ?? 'Sistem' }}</p>
                        </div>
                    </li>
                @empty
                    <li class="rounded-2xl bg-slate-50 px-4 py-5 text-sm text-slate-500">Belum ada aktivitas yang tercatat.</li>
                @endforelse
            </ol>
        </section>

        @if ($event->status->value === 'draft')
            <form action="{{ route('events.destroy', $event) }}" method="POST" class="flex justify-end" data-confirm-title="Hapus event draf ini?" data-confirm-message="Event dan pengaturan antreannya akan dihapus." data-confirm-variant="warning">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-ghost text-rose-600 hover:bg-rose-50">Hapus event draf</button>
            </form>
        @endif
    </div>
@endsection
