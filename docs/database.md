# Database Documentation

## Core tables

| Table | Purpose |
| --- | --- |
| `events` / `event_settings` | Active event, numbering mode/prefix, and male/female donor-bed capacity configuration |
| `event_donation_capacity_lanes` | One lockable mutex row per event and gender; it contains no duplicate capacity value |
| `participants` | Master participant identity |
| `event_participants` | Event registration, operational status, registration number, timestamps |
| `event_participant_services` | Selected Donor Darah / Pemeriksaan Kesehatan services |
| `event_participant_status_histories` | Immutable final-workflow transitions |
| `queue_tickets` | Registration and donor number allocation/history |
| `audit_logs` | Actor, subject, before/after operational audit |

## Integrity

- `(event_id, active_registration_number)` guarantees one active global registration number.
- `(event_id, number_scope, active_number)` guarantees one active number per donor lane/post scope.
- Composite foreign keys ensure a participant history/ticket belongs to the same event as its participant.
- `event_participants.status` is constrained to `ParticipantStatus` enum values.
- `event_participant_status_histories` has index `(event_id, to_status, created_at)` for operational history.
- `event_settings.donation_capacity_male` and `event_settings.donation_capacity_female` are unsigned integers with default `4`; each bounds simultaneous `donating` participants for its gender.
- `(event_id, gender)` is unique in `event_donation_capacity_lanes`. Its row-level lock is the capacity transaction mutex, so the male and female lanes do not block each other.
- Legacy `event_settings.donation_capacity` remains readable for compatible integrations only and must not be used for donor-capacity decisions.

## Reset antrean terkontrol

`EventQueueResetService` menghapus data operasional dengan filter `event_id` di dalam satu transaksi: `event_participants`, `queue_tickets`, `event_participant_services`, `event_participant_status_histories`, `health_assessments`, `donor_screenings`, dan `service_post_submissions`. Foreign key cascade menjadi backstop, sementara penghapusan eksplisit menjaga cakupan reset konsisten pada setiap database driver. Tabel `participants`, `events`, `event_settings`, `service_posts`, `event_donation_capacity_lanes`, pengguna, role, serta permission tidak disentuh.

Baris `audit_logs` selalu dipertahankan. Reset membuat audit baru dengan action `queue.reset` dan subject event yang tetap ada, sehingga pelacakan tidak bergantung pada data operasional yang telah dibersihkan.

`event_participants.status` uses `waiting`, `health_check`, `donating`, and `finished` for the active workflow. Older enum values and nullable historical columns are retained only for compatible data reads.
