<!DOCTYPE html>
<html lang="id" data-theme="emerald" data-realtime-driver="{{ config('foundation.realtime.driver') }}" data-polling-interval-ms="{{ config('foundation.realtime.polling_interval_ms') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="min-h-screen overflow-x-hidden bg-slate-50 text-slate-800 antialiased"
    x-data="{ sidebarOpen: false, profileOpen: false }"
    @keydown.escape.window="sidebarOpen = false; profileOpen = false"
>
    <div class="min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
        <div
            class="fixed inset-0 z-40 bg-slate-950/35 backdrop-blur-sm lg:hidden"
            x-cloak
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
        ></div>

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-68 max-w-[calc(100vw-2rem)] -translate-x-full flex-col border-r border-emerald-100 bg-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }"
        >
            <div class="flex h-20 items-center gap-3 border-b border-emerald-100 px-6">
                <div class="flex size-11 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-lg shadow-emerald-200">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.35-9.35-9.15C1.12 7.88 3.55 4.5 7.2 4.5c2.04 0 3.38 1.12 4.8 2.8 1.42-1.68 2.76-2.8 4.8-2.8 3.65 0 6.08 3.38 4.55 7.35C19.5 16.65 12 21 12 21Z"/>
                        <path stroke-linecap="round" d="M9 11.5h6M12 8.5v6"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-base font-extrabold tracking-tight text-slate-900">SEHAT-APP</p>
                    <p class="truncate text-xs font-medium text-emerald-700">Sistem Pelayanan Panitia</p>
                </div>
                <button
                    type="button"
                    class="btn btn-ghost btn-sm btn-square ml-auto lg:hidden"
                    @click="sidebarOpen = false"
                    aria-label="Tutup navigasi"
                >
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/>
                    </svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-4 py-6" aria-label="Navigasi utama">
                @can('administration.access')
                    <p class="px-3 text-[0.7rem] font-bold uppercase tracking-[0.18em] text-slate-400">Administration</p>
                    <ul class="mt-3 space-y-1">
                        <li>
                            <a href="{{ route('admin.dashboard') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('admin.dashboard'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('admin.dashboard')])>
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                                Dashboard
                            </a>
                        </li>
                        @can('events.manage')
                            <li><a href="{{ route('events.index') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('events.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('events.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3v3m8-3v3M4.5 9.5h15M6.5 5h11A1.5 1.5 0 0 1 19 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 17.5v-11A1.5 1.5 0 0 1 6.5 5Z"/><path stroke-linecap="round" d="M8 13h3m2 0h3m-8 3h3"/></svg>Event</a></li>
                        @endcan
                        @can('participants.manage')
                            <li><a href="{{ route('participants.index') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('participants.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('participants.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20m14-9a4 4 0 1 0 0-8m2 17v-1.5a4 4 0 0 0-3-3.87M11 6.5a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>Peserta</a></li>
                        @endcan
                        @can('users.manage')
                            <li><a href="{{ route('admin.users.index') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('admin.users.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('admin.users.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m17-9a3 3 0 1 0-6 0 3 3 0 0 0 6 0Zm-7-5a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>User & Permission</a></li>
                        @endcan
                        @can('audit-logs.view')
                            <li><a href="{{ route('admin.audit-logs.index') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('admin.audit-logs.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('admin.audit-logs.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 4H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h7l5 5v9a2 2 0 0 1-2 2Z"/></svg>Audit Log</a></li>
                        @endcan
                    </ul>
                @endcan

                @canany(['dashboard.view', 'check-in.manage', 'operations.manage', 'monitor.view'])
                    <p class="mt-7 px-3 text-[0.7rem] font-bold uppercase tracking-[0.18em] text-slate-400">Operasional</p>
                    <ul class="mt-3 space-y-1">
                        @can('dashboard.view')
                            <li><a href="{{ route('dashboard') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('dashboard'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('dashboard')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5Z"/></svg>Dashboard</a></li>
                        @endcan
                        @can('check-in.manage')
                            <li><a href="{{ route('check-ins.active') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('check-ins.*', 'events.check-ins.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('check-ins.*', 'events.check-ins.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h14v16H5zM8 8h8M8 12h5M8 16h3"/></svg>Registrasi</a></li>
                        @endcan
                        @can('operations.manage')
                            <li><a href="{{ route('operations.active') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('operations.*', 'events.operations.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('operations.*', 'events.operations.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M3 12h18M6.5 6.5l11 11m0-11-11 11"/></svg>Operasional</a></li>
                        @endcan
                        @can('monitor.view')
                            <li><a href="{{ route('monitor.active') }}" @class(['flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition', 'bg-emerald-50 text-emerald-800 shadow-sm ring-1 ring-emerald-100' => request()->routeIs('monitor.*', 'events.monitor.*'), 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs('monitor.*', 'events.monitor.*')])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2"/><path stroke-linecap="round" d="M8 21h8M12 17v4"/></svg>TV Monitor</a></li>
                        @endcan
                    </ul>
                @endcanany
            </nav>

            <div class="border-t border-emerald-100 p-4">
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-semibold text-slate-500">Akses saat ini</p>
                    <p class="mt-1 truncate text-sm font-bold text-slate-800">
                        {{ auth()->user()->getRoleNames()->first() ?? 'Tanpa role' }}
                    </p>
                </div>
            </div>
        </aside>

        <div class="min-w-0">
            <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
                <div class="flex h-16 items-center gap-2 px-3 sm:h-20 sm:gap-3 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="btn btn-ghost btn-square lg:hidden"
                        @click="sidebarOpen = true"
                        aria-label="Buka navigasi"
                    >
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/>
                        </svg>
                    </button>

                    <div class="min-w-0">
                        <p class="truncate text-lg font-bold tracking-tight text-slate-900">@yield('page-title', 'Dashboard')</p>
                        <p class="hidden text-xs text-slate-500 sm:block">@yield('page-subtitle', 'Ruang kerja operasional panitia')</p>
                    </div>

                    <div class="ml-auto flex items-center gap-2 sm:gap-3">
                        <div
                            class="hidden items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-semibold sm:flex"
                            x-data="realtimeStatus"
                            :class="badgeClass"
                            title="Status koneksi Laravel Reverb"
                        >
                            <span class="size-2 rounded-full" :class="dotClass"></span>
                            <span x-text="label">Menghubungkan</span>
                        </div>

                        <div class="relative">
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100"
                                @click="profileOpen = ! profileOpen"
                                :aria-expanded="profileOpen"
                            >
                                <span class="flex size-10 items-center justify-center rounded-xl bg-emerald-100 text-sm font-extrabold text-emerald-800">
                                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                                </span>
                                <span class="hidden max-w-40 text-left md:block">
                                    <span class="block truncate text-sm font-bold text-slate-800">{{ auth()->user()->name }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ auth()->user()->email }}</span>
                                </span>
                                <svg class="hidden size-4 text-slate-400 md:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m7 10 5 5 5-5"/>
                                </svg>
                            </button>

                            <div
                                class="absolute right-0 mt-2 w-56 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-200/70"
                                x-cloak
                                x-show="profileOpen"
                                x-transition.origin.top.right
                                @click.outside="profileOpen = false"
                            >
                                <div class="border-b border-slate-100 px-3 py-2.5">
                                    <p class="truncate text-sm font-bold text-slate-800">{{ auth()->user()->name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ auth()->user()->getRoleNames()->first() ?? 'Tanpa role' }}</p>
                                </div>
                                <form method="POST" action="{{ route('logout') }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-red-600 transition hover:bg-red-50">
                                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-3M9 12h12m0 0-3-3m3 3-3 3"/>
                                        </svg>
                                        Keluar
                                    </button>
                                </form>
                            </div>
                        </div>
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
