<?php

namespace App\Enums;

enum AuditAction: string
{
    case EventCreated = 'event.created';
    case EventUpdated = 'event.updated';
    case EventSettingsUpdated = 'event.settings_updated';
    case EventActivated = 'event.activated';
    case EventCompleted = 'event.completed';
    case EventCancelled = 'event.cancelled';
    case EventDeleted = 'event.deleted';
    case ServicePostCreated = 'service_post.created';
    case ServicePostUpdated = 'service_post.updated';
    case ServicePostReordered = 'service_post.reordered';
    case ServicePostActivated = 'service_post.activated';
    case ServicePostDeactivated = 'service_post.deactivated';
    case ServicePostDeleted = 'service_post.deleted';
    case ServicePostCompleted = 'service_post.completed';
    case ParticipantCreated = 'participant.created';
    case ParticipantUpdated = 'participant.updated';
    case ParticipantDeleted = 'participant.deleted';
    case ParticipantCheckedIn = 'participant.checked_in';
    case ParticipantRegistrationCancelled = 'participant.registration_cancelled';
    case QueueTicketCalled = 'queue_ticket.called';
    case QueueTicketServing = 'queue_ticket.serving';
    case QueueTicketSkipped = 'queue_ticket.skipped';
    case QueueTicketCancelled = 'queue_ticket.cancelled';
    case HealthAssessmentCompleted = 'health_assessment.completed';
    case HealthAssessmentUpdated = 'health_assessment.updated';
    case ScreeningEligible = 'screening.eligible';
    case ScreeningNotEligible = 'screening.not_eligible';
    case DonorTicketIssued = 'donor.ticket_issued';
    case DonationStarted = 'donation.started';
    case DonationCompleted = 'donation.completed';

    public function label(): string
    {
        return match ($this) {
            self::EventCreated => 'Event dibuat',
            self::EventUpdated => 'Data event diperbarui',
            self::EventSettingsUpdated => 'Pengaturan antrean diperbarui',
            self::EventActivated => 'Event diaktifkan',
            self::EventCompleted => 'Event diselesaikan',
            self::EventCancelled => 'Event dibatalkan',
            self::EventDeleted => 'Event dihapus',
            self::ServicePostCreated => 'Pos pelayanan dibuat',
            self::ServicePostUpdated => 'Pos pelayanan diperbarui',
            self::ServicePostReordered => 'Urutan pos pelayanan diubah',
            self::ServicePostActivated => 'Pos pelayanan diaktifkan',
            self::ServicePostDeactivated => 'Pos pelayanan dinonaktifkan',
            self::ServicePostDeleted => 'Pos pelayanan dihapus',
            self::ServicePostCompleted => 'Pelayanan pada pos selesai',
            self::ParticipantCreated => 'Peserta dibuat',
            self::ParticipantUpdated => 'Peserta diperbarui',
            self::ParticipantDeleted => 'Peserta dihapus',
            self::ParticipantCheckedIn => 'Peserta check-in',
            self::ParticipantRegistrationCancelled => 'Registrasi peserta dibatalkan',
            self::QueueTicketCalled => 'Nomor antrean dipanggil',
            self::QueueTicketServing => 'Pelayanan dimulai',
            self::QueueTicketSkipped => 'Nomor antrean dilewati',
            self::QueueTicketCancelled => 'Nomor antrean dibatalkan',
            self::HealthAssessmentCompleted => 'Pemeriksaan kesehatan selesai',
            self::HealthAssessmentUpdated => 'Hasil pemeriksaan diperbarui',
            self::ScreeningEligible => 'Peserta layak donor',
            self::ScreeningNotEligible => 'Peserta tidak layak donor',
            self::DonorTicketIssued => 'Nomor antrean donor diterbitkan',
            self::DonationStarted => 'Proses donor dimulai',
            self::DonationCompleted => 'Donor selesai',
        };
    }
}
