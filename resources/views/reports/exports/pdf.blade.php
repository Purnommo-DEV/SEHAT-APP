<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px; }
        body { color: #1e293b; font-family: DejaVu Sans, sans-serif; font-size: 8px; }
        h1 { color: #047857; font-size: 18px; margin: 0; }
        h2 { color: #334155; font-size: 11px; margin: 20px 0 8px; }
        p { margin: 3px 0; }
        .muted { color: #64748b; }
        .metrics { border-collapse: separate; border-spacing: 6px; margin: 14px -6px; width: 100%; }
        .metrics td { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 8px; width: 25%; }
        .metrics strong { color: #047857; display: block; font-size: 14px; margin-top: 4px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #047857; color: white; font-size: 7px; padding: 5px; text-align: left; }
        td { border: 1px solid #cbd5e1; padding: 4px; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style>
</head>
<body>
    <h1>Laporan Operasional Event</h1>
    <p><strong>{{ $snapshot->event->name }}</strong> · {{ $snapshot->event->code }}</p>
    <p class="muted">{{ $snapshot->event->location }} · Dibuat {{ $generatedAt->translatedFormat('d F Y H:i') }}</p>

    <table class="metrics"><tr>
        <td>Jumlah Hadir<strong>{{ $snapshot->metrics['checked_in'] }}</strong></td>
        <td>Memilih Donor<strong>{{ $snapshot->metrics['selected_donor'] }}</strong></td>
        <td>Cek Kesehatan<strong>{{ $snapshot->metrics['selected_health_check'] }}</strong></td>
        <td>Memilih Keduanya<strong>{{ $snapshot->metrics['selected_both'] }}</strong></td>
    </tr><tr>
        <td>Layak Donor<strong>{{ $snapshot->metrics['eligible_donor'] }}</strong></td>
        <td>Tidak Layak<strong>{{ $snapshot->metrics['not_eligible_donor'] }}</strong></td>
        <td>Donor Berhasil<strong>{{ $snapshot->metrics['donor_completed'] }}</strong></td>
        <td>Pemeriksaan Selesai<strong>{{ $snapshot->metrics['health_check_completed'] }}</strong></td>
    </tr></table>

    <h2>Rekap Peserta</h2>
    <table>
        <thead><tr><th>Registrasi / Peserta</th><th>Layanan</th><th>Pos / Status</th><th>Riwayat Antrean</th><th>Riwayat Layanan</th></tr></thead>
        <tbody>
            @forelse ($snapshot->records as $record)
                <tr>
                    <td><strong>{{ $record['registration_number'] }}</strong><br>{{ $record['name'] }}<br>{{ $record['phone'] }}</td>
                    <td>{{ $record['selected_services_text'] }}<br>Donor: {{ $record['donor_service_status_label'] ?? '—' }}<br>Kesehatan: {{ $record['health_check_status_label'] ?? '—' }}</td>
                    <td>{{ $record['current_post'] }}<br>{{ $record['status_label'] }}</td>
                    <td>{{ $record['queue_history_text'] ?: '—' }}</td>
                    <td>
                        @forelse ($record['service_history'] as $history)
                            <strong>{{ $history['post_name'] }}</strong>: {{ $history['details'] ?? $history['queue_status_label'] }}<br>
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">Belum ada peserta.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
