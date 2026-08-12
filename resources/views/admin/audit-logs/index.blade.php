@extends('layouts.app')

@section('title', 'Audit Log')
@section('page-title', 'Audit Log')
@section('page-subtitle', 'Jejak aktivitas administratif dan operasional yang tidak dapat diubah')

@section('content')
    <div class="mx-auto max-w-7xl">
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-5 sm:px-6"><h1 class="text-lg font-extrabold text-slate-900">Aktivitas terbaru</h1><p class="mt-1 text-sm text-slate-500">Audit reset antrean disimpan permanen walaupun data operasional event telah dihapus.</p></div>
            <div class="overflow-x-auto">
                <table class="table min-w-[760px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th>Waktu</th><th>Aksi</th><th>Event</th><th>Pelaku</th><th>Subjek</th></tr></thead>
                    <tbody>
                        @forelse ($auditLogs as $auditLog)
                            <tr>
                                <td class="whitespace-nowrap text-sm text-slate-600">{{ $auditLog->created_at->translatedFormat('d M Y, H:i') }}</td>
                                <td><p class="font-bold text-slate-900">{{ $auditLog->action->label() }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ $auditLog->action->value }}</p></td>
                                <td>{{ $auditLog->event?->name ?? 'Sistem' }}@if ($auditLog->event)<p class="mt-1 text-xs text-slate-500">{{ $auditLog->event->code }}</p>@endif</td>
                                <td>{{ $auditLog->user?->name ?? 'Sistem' }}</td>
                                <td class="font-mono text-xs text-slate-500">{{ class_basename($auditLog->subject_type) }} #{{ $auditLog->subject_id }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-14 text-center text-sm text-slate-500">Belum ada audit log.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($auditLogs->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $auditLogs->links() }}</div>@endif
        </section>
    </div>
@endsection
