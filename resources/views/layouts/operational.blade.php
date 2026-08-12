<!DOCTYPE html>
<html lang="id" data-theme="emerald" data-realtime-driver="{{ config('foundation.realtime.driver') }}" data-polling-interval-ms="{{ config('foundation.realtime.polling_interval_ms') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Operasional Panitia') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="min-h-screen overflow-x-hidden bg-slate-50 text-slate-800 antialiased"
    x-data="{ sidebarOpen: false }"
    @keydown.escape.window="sidebarOpen = false"
>
    <div class="min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
        <div class="fixed inset-0 z-40 bg-slate-950/35 backdrop-blur-sm lg:hidden" x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"></div>

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-68 max-w-[calc(100vw-2rem)] -translate-x-full flex-col border-r border-emerald-100 bg-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }"
        >
            <div class="flex h-20 items-center gap-3 border-b border-emerald-100 px-6">
                <span class="flex size-11 items-center justify-center rounded-2xl bg-emerald-600 text-xl font-black text-white shadow-lg shadow-emerald-200">+</span>
                <div class="min-w-0">
                    <p class="truncate text-base font-extrabold tracking-tight text-slate-900">SEHAT-APP</p>
                    <p class="truncate text-xs font-medium text-emerald-700">Operasional Panitia</p>
                </div>
                <button type="button" class="btn btn-ghost btn-sm btn-square ml-auto lg:hidden" @click="sidebarOpen = false" aria-label="Tutup navigasi">×</button>
            </div>

            <nav class="flex-1 overflow-y-auto px-4 py-6" aria-label="Navigasi operasional">
                <p class="px-3 text-[0.7rem] font-bold uppercase tracking-[0.18em] text-slate-400">Operasional</p>
                <ul class="mt-3 space-y-1">
                    @foreach ([
                        ['check-ins.active', 'Registrasi', 'check-ins.*|events.check-ins.*', []],
                        ['operations.active', 'Operasional', 'operations.*|events.operations.*|queues.*|events.service-queues.*', []],
                        ['dashboard', 'Dashboard', 'dashboard', []],
                        ['monitor.active', 'TV Monitor', 'monitor.*|events.monitor.*', []],
                    ] as [$route, $label, $patterns, $parameters])
                        @php($isActive = collect(explode('|', $patterns))->contains(fn (string $pattern): bool => request()->routeIs($pattern)))
                        <li>
                            <a href="{{ route($route, $parameters) }}" @class([
                                'flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition',
                                'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => $isActive,
                                'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $isActive,
                            ])>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="border-t border-emerald-100 p-4">
                <div class="rounded-2xl bg-emerald-50 p-4 text-sm">
                    <p class="font-bold text-emerald-900">Akses operasional</p>
                    <p class="mt-1 text-xs leading-5 text-emerald-700">Gunakan hanya pada event aktif yang ditugaskan kepada panitia.</p>
                </div>
            </div>
        </aside>

        <div class="min-w-0">
            <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
                <div class="flex h-16 items-center gap-3 px-3 sm:h-20 sm:px-6 lg:px-8">
                    <button type="button" class="btn btn-ghost btn-square lg:hidden" @click="sidebarOpen = true" aria-label="Buka navigasi">☰</button>
                    <div class="min-w-0">
                        <p class="truncate text-lg font-bold tracking-tight text-slate-900">@yield('page-title', 'Operasional Panitia')</p>
                        <p class="hidden text-xs text-slate-500 sm:block">@yield('page-subtitle', 'Pelayanan event aktif')</p>
                    </div>
                    <div class="ml-auto hidden items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-semibold sm:flex" x-data="realtimeStatus" :class="badgeClass">
                        <span class="size-2 rounded-full" :class="dotClass"></span>
                        <span x-text="label">Menghubungkan</span>
                    </div>
                </div>
            </header>

            <main class="min-w-0 p-3 sm:p-6 lg:p-8">
                @if (session('status'))
                    <div class="hidden" data-toast-message="{{ session('status') }}"></div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
