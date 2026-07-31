@extends('layouts.app')

@section('title', 'Pos Pelayanan')
@section('page-title', 'Pos Pelayanan')
@section('page-subtitle', 'Urutan jalur layanan untuk event terpilih')

@section('content')
    <div x-data="servicePostIndex(@js($servicePostsJson), @js(route('events.service-posts.data', $event)), {{ $event->id }})" class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div class="flex items-start gap-3">
                <a href="{{ route('events.show', $event) }}" class="btn btn-ghost btn-sm btn-square mt-0.5" aria-label="Kembali ke detail event">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                </a>
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ $event->code }}</p>
                    <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Pos pelayanan {{ $event->name }}</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        @if ($canBootstrapWorkflow)
                            Event aktif ini memerlukan minimal satu pos aktif sebelum Check-In dapat digunakan.
                        @elseif ($event->status->value === 'active' && $canCreateServicePost)
                            Event aktif ini memiliki layanan SOP yang belum lengkap. Tambahkan pos yang hilang tanpa mengubah histori yang sudah berjalan.
                        @else
                            Peserta akan bergerak mengikuti urutan di bawah ini. Atur seluruh pos sebelum event diaktifkan.
                        @endif
                    </p>
                </div>
            </div>
            @if ($canCreateServicePost)
                <a href="{{ route('events.service-posts.create', $event) }}" class="btn border-0 bg-emerald-600 text-white shadow-lg shadow-emerald-200 hover:bg-emerald-700">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                    Tambah Pos Pelayanan
                </a>
            @endif
        </div>

        <div class="rounded-3xl border border-emerald-100 bg-emerald-50/70 p-5 text-sm leading-6 text-emerald-800 sm:p-6">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M10 3h4l6 6v6l-6 6h-4l-6-6V9l6-6Z"/></svg>
                <p>
                    @if ($canBootstrapWorkflow)
                        Event aktif belum memiliki Jalur Pelayanan. Tambahkan satu pos aktif sekarang; pengaturan lain tetap dikunci untuk menjaga alur pelayanan.
                    @elseif ($event->status->value === 'active' && $canCreateServicePost)
                        Pos yang sudah ada tetap dikunci. Anda hanya dapat menambahkan pos untuk layanan SOP yang belum tersedia dan pos tersebut langsung aktif.
                    @elseif ($event->status->value === 'draft')
                        Urutan, data, dan pengaturan awal pos dapat diubah selama event masih draf. Saat event aktif, hanya status aktif/nonaktif pos yang dapat diatur agar alur yang sedang berjalan tidak berubah.
                    @else
                        Event ini berstatus <strong>{{ $event->status->label() }}</strong>. Perubahan urutan dan data pos dikunci untuk menjaga konsistensi alur pelayanan.
                    @endif
                </p>
            </div>
        </div>

        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 class="font-bold text-slate-900">Jalur pelayanan</h2>
                    <p class="mt-1 text-sm text-slate-500"><span x-text="posts.length"></span> pos dalam urutan layanan</p>
                </div>
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                    <span class="size-2 rounded-full" :class="isRefreshing ? 'animate-pulse bg-amber-400' : 'bg-emerald-500'"></span>
                    <span x-text="isRefreshing ? 'Memperbarui realtime' : 'Realtime aktif saat Reverb berjalan'"></span>
                </div>
            </div>

            <div x-show="posts.length === 0" class="px-6 py-16 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10l4 4v10l-4 4H7l-4-4V7l4-4Z"/><path stroke-linecap="round" d="M8 12h8M12 8v8"/></svg>
                </div>
                <p class="mt-4 font-bold text-slate-800">Belum ada pos pelayanan</p>
                <p class="mt-1 text-sm text-slate-500">Tambahkan pos pertama untuk membangun urutan pelayanan event.</p>
                @if ($canCreateServicePost)
                    <a href="{{ route('events.service-posts.create', $event) }}" class="btn mt-5 border-0 bg-emerald-600 text-white hover:bg-emerald-700">Tambah Pos Pelayanan</a>
                @endif
            </div>

            <ol x-show="posts.length > 0" x-cloak class="divide-y divide-slate-100">
                <template x-for="(post, index) in posts" :key="post.id">
                    <li class="flex flex-col gap-4 px-5 py-5 sm:px-6 lg:flex-row lg:items-center">
                        <div class="flex min-w-0 flex-1 items-start gap-4">
                            <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-slate-100 font-mono text-sm font-extrabold text-slate-700" x-text="String(post.sequence).padStart(2, '0')"></div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-bold text-slate-900" x-text="post.name"></p>
                                    <span class="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs font-bold text-slate-600" x-text="post.code"></span>
                                    <span class="rounded-md bg-sky-50 px-2 py-1 text-xs font-bold text-sky-700" x-text="post.behavior_label"></span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold" :class="post.is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500'">
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        <span x-text="post.is_active ? 'Aktif' : 'Nonaktif'"></span>
                                    </span>
                                </div>
                                <p x-show="post.description" x-cloak class="mt-2 text-sm leading-6 text-slate-500" x-text="post.description"></p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                            @if ($event->status->value === 'draft')
                                <div class="join">
                                    <form :action="post.urls.move" method="POST">
                                        @csrf
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="btn btn-sm join-item" :disabled="index === 0" aria-label="Naikkan urutan">↑</button>
                                    </form>
                                    <form :action="post.urls.move" method="POST">
                                        @csrf
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="btn btn-sm join-item" :disabled="index === posts.length - 1" aria-label="Turunkan urutan">↓</button>
                                    </form>
                                </div>
                            @endif

                            @if (in_array($event->status->value, ['draft', 'active'], true))
                                <form :action="post.urls.toggle" method="POST" :data-confirm-title="(post.is_active ? 'Nonaktifkan ' : 'Aktifkan ') + post.name + '?'" data-confirm-message="Perubahan status akan langsung dikirim ke panitia lain.">
                                    @csrf
                                    <button type="submit" class="btn btn-sm" :class="post.is_active ? 'btn-ghost text-slate-600' : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'" x-text="post.is_active ? 'Nonaktifkan' : 'Aktifkan'"></button>
                                </form>
                            @endif

                            @if ($event->status->value === 'draft')
                                <a :href="post.urls.edit" class="btn btn-ghost btn-sm">Ubah</a>
                                <form :action="post.urls.destroy" method="POST" :data-confirm-title="'Hapus ' + post.name + '?'" data-confirm-message="Pos ini akan dihapus dari urutan layanan." data-confirm-variant="warning">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </li>
                </template>
            </ol>
        </div>

        <p x-show="lastError" x-cloak class="text-center text-sm font-semibold text-rose-600" x-text="lastError"></p>
    </div>
@endsection
