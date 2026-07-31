<table>
    <thead>
        <tr><th colspan="14">Laporan Operasional {{ $snapshot->event->name }}</th></tr>
        <tr><th colspan="14">{{ $snapshot->event->code }} · {{ $snapshot->event->location }}</th></tr>
        <tr></tr>
        <tr>
            <th>No. Registrasi</th><th>Nama</th><th>NIK</th><th>Nomor HP</th><th>Jenis Kelamin</th><th>Layanan Dipilih</th><th>Status Donor</th><th>Status Kesehatan</th><th>Check-in</th><th>Pos Saat Ini</th><th>Status</th><th>Riwayat Antrean</th><th>Riwayat Layanan</th><th>Hasil Screening</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($snapshot->records as $record)
            <tr>
                <td>{{ $record['registration_number'] }}</td><td>{{ $record['name'] }}</td><td>{{ $record['nik'] }}</td><td>{{ $record['phone'] }}</td><td>{{ $record['gender'] }}</td><td>{{ $record['selected_services_text'] }}</td><td>{{ $record['donor_service_status_label'] }}</td><td>{{ $record['health_check_status_label'] }}</td><td>{{ $record['checked_in_at'] }}</td><td>{{ $record['current_post'] }}</td><td>{{ $record['status_label'] }}</td><td>{{ $record['queue_history_text'] }}</td><td>{{ $record['service_history_text'] }}</td><td>{{ $record['screening_result'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
