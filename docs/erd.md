# ERD

```mermaid
erDiagram
    EVENTS ||--|| EVENT_SETTINGS : configures
    EVENTS ||--o{ EVENT_DONATION_CAPACITY_LANES : locks
    EVENTS ||--o{ SERVICE_POSTS : has
    EVENTS ||--o{ EVENT_PARTICIPANTS : contains
    PARTICIPANTS ||--o{ EVENT_PARTICIPANTS : registers
    EVENT_PARTICIPANTS ||--o{ EVENT_PARTICIPANT_SERVICES : selects
    EVENT_PARTICIPANTS ||--o{ EVENT_PARTICIPANT_STATUS_HISTORIES : transitions
    EVENT_PARTICIPANTS ||--o{ QUEUE_TICKETS : receives
    EVENTS ||--o{ AUDIT_LOGS : audits
    USERS ||--o{ AUDIT_LOGS : acts
```

`EVENT_SETTINGS` stores numbering configuration plus `donation_capacity_male` and `donation_capacity_female`, the independent maxima for `donating` participants. `EVENT_DONATION_CAPACITY_LANES` holds one lockable row for each event/gender and deliberately does not duplicate capacity values. The legacy `donation_capacity` field is retained only for compatibility. The status-history row stores `from_status`, `to_status`, `changed_by`, and `created_at`. Queue tickets preserve released numbers by nulling `active_number` instead of deleting history.
