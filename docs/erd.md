# ERD Final

ERD ini menggambarkan schema operasional setelah migration `2026_07_30_000028_make_participant_phone_optional.php`.

```mermaid
erDiagram
    USERS {
        bigint id PK
        string name
        string email UK
        boolean is_active
    }

    EVENTS {
        bigint id PK
        string code UK
        string name
        string status
        string active_marker UK
        timestamp starts_at
        timestamp ends_at
        bigint created_by FK
    }

    EVENT_SETTINGS {
        bigint id PK
        bigint event_id FK
        string registration_number_format
        string registration_queue_prefix
        string registration_male_prefix
        string registration_female_prefix
        int registration_queue_digits
        string donor_number_mode
        string donor_queue_prefix
        int donor_queue_digits
        string male_donor_queue_prefix
        string female_donor_queue_prefix
    }

    PARTICIPANTS {
        bigint id PK
        string nik UK
        string name
        string phone
        string gender
        date birth_date
    }

    SERVICE_POSTS {
        bigint id PK
        bigint event_id FK
        string code
        string type
        string behavior
        string queue_prefix
        int queue_number_digits
        int sequence
        boolean is_active
    }

    EVENT_PARTICIPANTS {
        bigint id PK
        bigint event_id FK
        bigint participant_id FK
        int registration_number
        int active_registration_number
        bigint current_service_post_id FK
        string status
        timestamp checked_in_at
        bigint checked_in_by FK
        timestamp completed_at
        timestamp cancelled_at
        bigint cancelled_by FK
    }

    EVENT_PARTICIPANT_SERVICES {
        bigint id PK
        bigint event_id FK
        bigint event_participant_id FK
        string service
        string status
        timestamp selected_at
        timestamp eligibility_started_at
        timestamp eligibility_completed_at
        timestamp started_at
        timestamp completed_at
    }

    QUEUE_TICKETS {
        bigint id PK
        bigint event_id FK
        bigint event_participant_id FK
        bigint service_post_id FK
        string queue_type
        int number
        string number_scope
        int active_number
        string status
        timestamp called_at
        bigint called_by FK
        timestamp served_at
        timestamp finished_at
        timestamp cancelled_at
        bigint cancelled_by FK
    }

    DONOR_SCREENINGS {
        bigint id PK
        bigint event_id FK
        bigint event_participant_id FK
        bigint service_post_id FK
        string result
        string reason
        bigint screened_by FK
    }

    HEALTH_ASSESSMENTS {
        bigint id PK
        bigint event_id FK
        bigint event_participant_id FK
        bigint service_post_id FK
        string blood_pressure
        decimal blood_sugar
        decimal cholesterol
        decimal uric_acid
        bigint created_by FK
    }

    SERVICE_POST_SUBMISSIONS {
        bigint id PK
        bigint event_id FK
        bigint event_participant_id FK
        bigint service_post_id FK
        json payload
        bigint completed_by FK
        timestamp completed_at
    }

    AUDIT_LOGS {
        bigint id PK
        bigint event_id FK
        bigint user_id FK
        string action
        string subject_type
        bigint subject_id
        json old_values
        json new_values
        timestamp created_at
    }

    EVENTS ||--|| EVENT_SETTINGS : has
    USERS ||--o{ EVENTS : creates
    EVENTS ||--o{ SERVICE_POSTS : configures
    EVENTS ||--o{ EVENT_PARTICIPANTS : contains
    PARTICIPANTS ||--o{ EVENT_PARTICIPANTS : attends
    SERVICE_POSTS o|--o{ EVENT_PARTICIPANTS : current_position
    USERS o|--o{ EVENT_PARTICIPANTS : checks_in
    USERS o|--o{ EVENT_PARTICIPANTS : cancels

    EVENT_PARTICIPANTS ||--o{ EVENT_PARTICIPANT_SERVICES : selects
    EVENTS ||--o{ EVENT_PARTICIPANT_SERVICES : scopes

    EVENT_PARTICIPANTS ||--o{ QUEUE_TICKETS : receives
    SERVICE_POSTS ||--o{ QUEUE_TICKETS : owns
    USERS o|--o{ QUEUE_TICKETS : calls
    USERS o|--o{ QUEUE_TICKETS : cancels

    EVENT_PARTICIPANTS ||--o{ DONOR_SCREENINGS : screened
    SERVICE_POSTS o|--o{ DONOR_SCREENINGS : captures
    USERS ||--o{ DONOR_SCREENINGS : decides

    EVENT_PARTICIPANTS ||--o{ HEALTH_ASSESSMENTS : assessed
    SERVICE_POSTS o|--o{ HEALTH_ASSESSMENTS : captures
    USERS ||--o{ HEALTH_ASSESSMENTS : records

    EVENT_PARTICIPANTS ||--o{ SERVICE_POST_SUBMISSIONS : submits
    SERVICE_POSTS ||--o{ SERVICE_POST_SUBMISSIONS : records
    USERS ||--o{ SERVICE_POST_SUBMISSIONS : completes

    EVENTS o|--o{ AUDIT_LOGS : scopes
    USERS o|--o{ AUDIT_LOGS : acts
```

## Relasi operator dan permission

```mermaid
erDiagram
    USERS {
        bigint id PK
    }
    SERVICE_POSTS {
        bigint id PK
    }
    SERVICE_POST_USER {
        bigint service_post_id PK
        bigint user_id PK
    }
    ROLES {
        bigint id PK
        string name
        string guard_name
    }
    PERMISSIONS {
        bigint id PK
        string name
        string guard_name
    }
    MODEL_HAS_ROLES {
        bigint role_id FK
        string model_type
        bigint model_id
    }
    ROLE_HAS_PERMISSIONS {
        bigint role_id FK
        bigint permission_id FK
    }

    USERS ||--o{ SERVICE_POST_USER : assigned
    SERVICE_POSTS ||--o{ SERVICE_POST_USER : operated_by
    USERS ||--o{ MODEL_HAS_ROLES : receives
    ROLES ||--o{ MODEL_HAS_ROLES : assigned
    ROLES ||--o{ ROLE_HAS_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : included
```

`audit_logs.subject_type` dan `subject_id` adalah relasi polymorphic. Karena itu relasi ke seluruh subject audit tidak digambar sebagai foreign key fisik.

## Constraint bisnis utama

- Satu event aktif dijaga oleh unique `events.active_marker`; event aktif memakai nilai `active`.
- Satu identitas peserta hanya boleh hadir satu kali per event: unique `(event_id, participant_id)`.
- Satu layanan hanya boleh dipilih satu kali per peserta event: unique `(event_participant_id, service)`.
- Satu peserta hanya memiliki satu tiket per pos: unique `(event_participant_id, service_post_id)`.
- Nomor registrasi aktif unik per event: unique `(event_id, active_registration_number)`.
- Nomor antrean aktif unik per scope: unique `(event_id, number_scope, active_number)`.
- Composite foreign key menjaga `event_id` pada peserta, layanan, pos, tiket, screening, dan pemeriksaan tetap berada dalam event yang sama.
- CHECK constraint menjaga nilai enum, rentang digit, timeline tiket, angka positif, dan konsistensi nomor historis dengan nomor aktif.
