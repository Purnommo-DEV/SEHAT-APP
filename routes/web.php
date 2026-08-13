<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserPermissionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CheckIn\ActiveCheckInController;
use App\Http\Controllers\CheckIn\CheckInController;
use App\Http\Controllers\CheckIn\CheckInDataController;
use App\Http\Controllers\CheckIn\QuickParticipantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardDataController;
use App\Http\Controllers\Donation\ActiveDonationController;
use App\Http\Controllers\Donation\DonorQueueController;
use App\Http\Controllers\Event\EventController;
use App\Http\Controllers\Event\EventDataController;
use App\Http\Controllers\Event\EventQueueResetController;
use App\Http\Controllers\Event\EventSettingsController;
use App\Http\Controllers\Event\EventStatusController;
use App\Http\Controllers\Health\ActiveHealthController;
use App\Http\Controllers\Health\HealthAssessmentController;
use App\Http\Controllers\Health\HealthQueueController;
use App\Http\Controllers\Monitor\ActiveMonitorController;
use App\Http\Controllers\Monitor\MonitorController;
use App\Http\Controllers\Operational\ActiveOperationalController;
use App\Http\Controllers\Operational\DonationCapacityController;
use App\Http\Controllers\Operational\OperationalWorkflowController;
use App\Http\Controllers\Participant\ParticipantAutocompleteController;
use App\Http\Controllers\Participant\ParticipantController;
use App\Http\Controllers\Participant\ParticipantDataController;
use App\Http\Controllers\Report\ReportController;
use App\Http\Controllers\Screening\ActiveScreeningController;
use App\Http\Controllers\Screening\DonorScreeningController;
use App\Http\Controllers\ServicePost\ServicePostController;
use App\Http\Controllers\ServicePost\ServicePostDataController;
use App\Http\Controllers\ServicePost\ServicePostStatusController;
use App\Http\Controllers\ServiceQueue\ActiveServiceQueueController;
use App\Http\Controllers\ServiceQueue\ServiceQueueController;
use App\Http\Controllers\System\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/ready', ReadinessController::class)
    ->middleware('throttle:health')
    ->name('health.ready');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

/*
|--------------------------------------------------------------------------
| Operational panitia (public)
|--------------------------------------------------------------------------
|
| Panitia works from event-specific links and does not authenticate. Every
| request stays in the web middleware group (including CSRF), is rate-limited,
| and event-specific URLs are limited to the one active event by
| operational.event plus scoped route binding.
|
*/
Route::middleware('throttle:operational')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/dashboard/data', DashboardDataController::class)->name('dashboard.data');

    Route::get('/check-ins', ActiveCheckInController::class)->name('check-ins.active');
    Route::get('/operations', ActiveOperationalController::class)->name('operations.active');
    Route::get('/operations/waiting/desk', [ActiveOperationalController::class, 'waitingDesk'])
        ->name('operations.waiting.desk');
    Route::get('/operations/{stage}', ActiveOperationalController::class)
        ->whereIn('stage', ['waiting', 'health-check', 'eligibility', 'before-donor', 'donating', 'completed'])
        ->name('operations.stage');
    Route::get('/my-queue', ActiveServiceQueueController::class)->name('queues.active');
    Route::get('/monitor', ActiveMonitorController::class)->name('monitor.active');

    Route::scopeBindings()->middleware('operational.event')->group(function (): void {
        Route::prefix('events/{event}/check-ins')->name('events.check-ins.')->group(function (): void {
            Route::get('/', [CheckInController::class, 'index'])->name('index');
            Route::get('/data', CheckInDataController::class)->name('data');
            Route::get('/participants/autocomplete', ParticipantAutocompleteController::class)
                ->name('participants.autocomplete');
            Route::post('/participants', QuickParticipantController::class)->name('participants.store');
            Route::post('/', [CheckInController::class, 'store'])->name('store');
            Route::get('/{eventParticipant}', [CheckInController::class, 'show'])->name('show');
        });

        Route::prefix('events/{event}/operations')->name('events.operations.')->group(function (): void {
            Route::get('/', [OperationalWorkflowController::class, 'index'])->name('index');
            Route::get('/data', [OperationalWorkflowController::class, 'snapshot'])->name('snapshot');
            Route::get('/waiting', [OperationalWorkflowController::class, 'waiting'])->name('waiting');
            Route::get('/waiting/data', [OperationalWorkflowController::class, 'waitingSnapshot'])
                ->name('waiting.snapshot');
            Route::get('/waiting/desk', [OperationalWorkflowController::class, 'waitingDesk'])
                ->name('waiting.desk');
            Route::post('/waiting/next', [OperationalWorkflowController::class, 'next'])
                ->name('waiting.next');
            Route::get('/health-check', [OperationalWorkflowController::class, 'healthCheck'])->name('health-check');
            Route::get('/before-donor', [OperationalWorkflowController::class, 'beforeDonation'])->name('before-donor');
            Route::get('/donating', [OperationalWorkflowController::class, 'donating'])->name('donating');
            Route::get('/completed', [OperationalWorkflowController::class, 'completed'])->name('completed');
            Route::get('/data/{stage}', [OperationalWorkflowController::class, 'data'])
                ->whereIn('stage', ['waiting', 'calling', 'health_check', 'waiting_screening', 'donating', 'finished'])
                ->name('data');
            Route::post('/{eventParticipant}/health-check', [OperationalWorkflowController::class, 'startHealthCheck'])
                ->name('health-check.start');
            Route::post('/{eventParticipant}/eligibility', [OperationalWorkflowController::class, 'startEligibility'])
                ->name('eligibility.start');
            Route::post('/{eventParticipant}/eligibility/eligible', [OperationalWorkflowController::class, 'markEligible'])
                ->name('eligibility.eligible');
            Route::post('/{eventParticipant}/eligibility/ineligible', [OperationalWorkflowController::class, 'markIneligible'])
                ->name('eligibility.ineligible');
            Route::post('/{eventParticipant}/donate', [OperationalWorkflowController::class, 'startDonation'])
                ->name('donating.start');
            Route::post('/{eventParticipant}/complete', [OperationalWorkflowController::class, 'complete'])
                ->name('completed.store');
            Route::post('/{eventParticipant}/complete-health', [OperationalWorkflowController::class, 'completeBeforeDonation'])
                ->name('health-check.complete');
        });

        Route::prefix('events/{event}/service-posts/{servicePost}/queue')
            ->name('events.service-queues.')
            ->group(function (): void {
                Route::get('/', [ServiceQueueController::class, 'index'])->name('index');
                Route::get('/data', [ServiceQueueController::class, 'data'])->name('data');
                Route::post('/tickets/{queueTicket}/call', [ServiceQueueController::class, 'call'])->name('call');
                Route::post('/tickets/{queueTicket}/goto', [ServiceQueueController::class, 'goto'])->name('goto');
                Route::post('/tickets/{queueTicket}/skip', [ServiceQueueController::class, 'skip'])->name('skip');
            });

        Route::prefix('events/{event}/monitor')->name('events.monitor.')->group(function (): void {
            Route::get('/', [MonitorController::class, 'show'])->name('show');
            Route::get('/data', [MonitorController::class, 'data'])->name('data');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Admin and management (authenticated)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'throttle:operational'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', AdminDashboardController::class)
            ->middleware('permission:'.PermissionName::AccessAdministration->value)
            ->name('dashboard');
        Route::get('/users', [UserPermissionController::class, 'index'])
            ->middleware('permission:'.PermissionName::ManageUsers->value)
            ->name('users.index');
        Route::get('/audit', [AuditLogController::class, 'index'])
            ->middleware('permission:'.PermissionName::ViewAuditLogs->value)
            ->name('audit-logs.index');
    });

    // Legacy entry points remain available to authorised administrators only.
    Route::get('/health', ActiveHealthController::class)
        ->middleware('permission:'.PermissionName::ManageHealth->value)
        ->name('health.active');
    Route::get('/screening', ActiveScreeningController::class)
        ->middleware('permission:'.PermissionName::ManageScreening->value)
        ->name('screening.active');
    Route::get('/donation', ActiveDonationController::class)
        ->middleware('permission:'.PermissionName::ManageDonation->value)
        ->name('donation.active');

    Route::prefix('reports')
        ->name('reports.')
        ->middleware('permission:'.PermissionName::ViewReports->value)
        ->group(function (): void {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            Route::get('/data', [ReportController::class, 'data'])->name('data');
            Route::get('/excel', [ReportController::class, 'excel'])
                ->middleware('throttle:reports')
                ->name('excel');
            Route::get('/pdf', [ReportController::class, 'pdf'])
                ->middleware('throttle:reports')
                ->name('pdf');
        });

    Route::prefix('events')->name('events.')->middleware('permission:'.PermissionName::ManageEvents->value)->group(function (): void {
        Route::get('/data', EventDataController::class)->name('data');
        Route::post('/{event}/activate', [EventStatusController::class, 'activate'])->name('activate');
        Route::post('/{event}/complete', [EventStatusController::class, 'complete'])->name('complete');
        Route::post('/{event}/cancel', [EventStatusController::class, 'cancel'])->name('cancel');
        Route::get('/{event}/settings', [EventSettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('/{event}/settings', [EventSettingsController::class, 'update'])->name('settings.update');
    });
    Route::resource('events', EventController::class);

    Route::prefix('participants')->name('participants.')->middleware('permission:'.PermissionName::ManageParticipants->value)->group(function (): void {
        Route::get('/data', ParticipantDataController::class)->name('data');
        Route::get('/autocomplete', ParticipantAutocompleteController::class)->name('autocomplete');
    });
    Route::resource('participants', ParticipantController::class)->except('show');

    Route::patch('/events/{event}/operations/donation-capacity', [DonationCapacityController::class, 'update'])
        ->middleware([
            'operational.event',
            'permission:'.PermissionName::UpdateDonationCapacity->value,
        ])
        ->name('events.operations.donation-capacity.update');

    Route::post('/events/{event}/queue-reset', [EventQueueResetController::class, 'store'])
        ->middleware('permission:'.PermissionName::ResetEventQueue->value)
        ->name('events.queue-reset.store');

    Route::scopeBindings()->group(function (): void {
        Route::prefix('events/{event}/check-ins')
            ->name('events.check-ins.')
            ->middleware('permission:'.PermissionName::ManageCheckIn->value)
            ->group(function (): void {
                Route::patch('/{eventParticipant}', [CheckInController::class, 'update'])->name('update');
                Route::post('/{eventParticipant}/cancel', [CheckInController::class, 'cancel'])->name('cancel');
            });

        // Historical service-post actions remain management-only. The public
        // screen intentionally exposes only Next, Skip, and Goto.
        Route::prefix('events/{event}/service-posts/{servicePost}/queue')
            ->name('events.service-queues.')
            ->middleware('permission:'.PermissionName::ManageOwnQueue->value)
            ->group(function (): void {
                Route::post('/tickets/{queueTicket}/start', [ServiceQueueController::class, 'start'])->name('start');
                Route::post('/tickets/{queueTicket}/cancel', [ServiceQueueController::class, 'cancel'])->name('cancel');
                Route::post('/tickets/{queueTicket}/complete', [ServiceQueueController::class, 'complete'])->name('complete');
            });

        Route::prefix('events/{event}/donation')
            ->name('events.donation.')
            ->middleware('permission:'.PermissionName::ManageDonation->value)
            ->group(function (): void {
                Route::get('/', [DonorQueueController::class, 'index'])->name('index');
                Route::get('/data', [DonorQueueController::class, 'data'])->name('data');
                Route::post('/tickets/{queueTicket}/call', [DonorQueueController::class, 'call'])->name('call');
                Route::post('/tickets/{queueTicket}/start', [DonorQueueController::class, 'start'])->name('start');
                Route::post('/tickets/{queueTicket}/skip', [DonorQueueController::class, 'skip'])->name('skip');
                Route::post('/tickets/{queueTicket}/cancel', [DonorQueueController::class, 'cancel'])->name('cancel');
                Route::post('/tickets/{queueTicket}/complete', [DonorQueueController::class, 'complete'])->name('complete');
            });

        Route::prefix('events/{event}/screening')
            ->name('events.screening.')
            ->middleware('permission:'.PermissionName::ManageScreening->value)
            ->group(function (): void {
                Route::get('/', [DonorScreeningController::class, 'index'])->name('index');
                Route::get('/data', [DonorScreeningController::class, 'data'])->name('data');
                Route::post('/{eventParticipant}', [DonorScreeningController::class, 'store'])->name('store');
            });

        Route::prefix('events/{event}/health')
            ->name('events.health.')
            ->middleware('permission:'.PermissionName::ManageHealth->value)
            ->group(function (): void {
                Route::get('/', [HealthQueueController::class, 'index'])->name('index');
                Route::get('/data', [HealthQueueController::class, 'data'])->name('data');
                Route::post('/tickets/{queueTicket}/call', [HealthQueueController::class, 'call'])->name('call');
                Route::post('/tickets/{queueTicket}/start', [HealthQueueController::class, 'start'])->name('start');
                Route::post('/tickets/{queueTicket}/skip', [HealthQueueController::class, 'skip'])->name('skip');
                Route::get('/tickets/{queueTicket}/assessment', [HealthAssessmentController::class, 'edit'])->name('assessments.edit');
                Route::post('/tickets/{queueTicket}/assessment', [HealthAssessmentController::class, 'store'])->name('assessments.store');
                Route::patch('/assessments/{healthAssessment}', [HealthAssessmentController::class, 'update'])->name('assessments.update');
            });

        Route::prefix('events/{event}/service-posts')
            ->name('events.service-posts.')
            ->middleware('permission:'.PermissionName::ManageServicePosts->value)
            ->group(function (): void {
                Route::get('/data', ServicePostDataController::class)->name('data');
                Route::post('/{servicePost}/move', [ServicePostStatusController::class, 'move'])->name('move');
                Route::post('/{servicePost}/toggle', [ServicePostStatusController::class, 'toggle'])->name('toggle');
            });
        Route::resource('events/{event}/service-posts', ServicePostController::class)
            ->middleware('permission:'.PermissionName::ManageServicePosts->value)
            ->names('events.service-posts')
            ->parameters(['service-posts' => 'servicePost'])
            ->except('show');
    });
});
