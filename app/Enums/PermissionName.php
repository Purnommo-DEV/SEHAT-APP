<?php

namespace App\Enums;

enum PermissionName: string
{
    case AccessAdministration = 'administration.access';
    case ViewDashboard = 'dashboard.view';
    case ManageEvents = 'events.manage';
    case ManageServicePosts = 'service-posts.manage';
    case ManageParticipants = 'participants.manage';
    case ManageCheckIn = 'check-in.manage';
    case ManageOperationalWorkflow = 'operations.manage';
    case UpdateDonationCapacity = 'event.update_donation_capacity';
    case ResetEventQueue = 'event.reset_queue';
    case ViewAuditLogs = 'audit-logs.view';
    case ManageOwnQueue = 'queues.manage';
    case ManageHealth = 'health.manage';
    case ManageScreening = 'screening.manage';
    case ManageDonation = 'donation.manage';
    case ViewMonitor = 'monitor.view';
    case ViewReports = 'reports.view';
    case ManageUsers = 'users.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
