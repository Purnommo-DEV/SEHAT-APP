@extends('layouts.app')

@section('title', 'User & Permission')
@section('page-title', 'User & Permission')
@section('page-subtitle', 'Akun aktif serta hak akses berbasis role dan permission')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">
                <h1 class="text-lg font-extrabold text-slate-900">Akun pengguna</h1>
                <p class="mt-1 text-sm text-slate-500">Role dan permission efektif dimuat langsung dari konfigurasi Spatie Permission.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="table min-w-[760px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Pengguna</th><th>Status</th><th>Role</th><th>Permission efektif</th></tr></thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr>
                                <td><p class="font-bold text-slate-900">{{ $user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $user->email }}</p></td>
                                <td><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-700' => $user->is_active, 'bg-slate-100 text-slate-600' => ! $user->is_active])>{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td><div class="flex max-w-56 flex-wrap gap-1.5">@forelse ($user->roles as $role)<span class="rounded-full bg-indigo-50 px-2 py-1 text-xs font-bold text-indigo-700">{{ $role->name }}</span>@empty<span class="text-sm text-slate-400">Tanpa role</span>@endforelse</div></td>
                                <td><div class="flex max-w-md flex-wrap gap-1.5">@forelse ($user->getAllPermissions() as $permission)<span class="rounded-full bg-slate-100 px-2 py-1 font-mono text-[0.68rem] font-semibold text-slate-600">{{ $permission->name }}</span>@empty<span class="text-sm text-slate-400">Tidak ada permission</span>@endforelse</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-14 text-center text-sm text-slate-500">Belum ada pengguna.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($users->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $users->links() }}</div>@endif
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-5 sm:px-6"><h2 class="font-extrabold text-slate-900">Role</h2></div>
                <div class="divide-y divide-slate-100">
                    @foreach ($roles as $role)
                        <article class="p-5"><h3 class="font-bold text-slate-900">{{ $role->name }}</h3><div class="mt-3 flex flex-wrap gap-1.5">@forelse ($role->permissions as $permission)<span class="rounded-full bg-emerald-50 px-2 py-1 font-mono text-[0.68rem] font-semibold text-emerald-700">{{ $permission->name }}</span>@empty<span class="text-sm text-slate-400">Belum memiliki permission.</span>@endforelse</div></article>
                    @endforeach
                </div>
            </div>
            <aside class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-extrabold text-slate-900">Permission tersedia</h2><div class="mt-4 flex flex-wrap gap-1.5">@foreach ($permissions as $permission)<span class="rounded-full bg-slate-100 px-2 py-1 font-mono text-[0.68rem] font-semibold text-slate-600">{{ $permission->name }}</span>@endforeach</div></aside>
        </section>
    </div>
@endsection
