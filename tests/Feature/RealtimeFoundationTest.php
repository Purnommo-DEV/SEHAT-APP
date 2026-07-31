<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Events\EventLifecycleUpdated;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RealtimeFoundationTest extends TestCase
{
    public function test_reverb_connection_is_configured(): void
    {
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertArrayHasKey('reverb', config('reverb.servers'));
        $this->assertNotEmpty(config('reverb.apps.apps'));
    }

    public function test_reverb_allowed_origins_are_normalized_to_hostnames(): void
    {
        $key = 'REVERB_ALLOWED_ORIGINS';
        $originalEnvironment = $_ENV[$key] ?? null;
        $originalServer = $_SERVER[$key] ?? null;
        $originalValue = getenv($key);

        $_ENV[$key] = 'http://127.0.0.1:8000,https://localhost:8000';
        $_SERVER[$key] = $_ENV[$key];
        putenv("{$key}={$_ENV[$key]}");

        try {
            /** @var array{apps: array{apps: list<array{allowed_origins: list<string>}>}} $reverb */
            $reverb = require config_path('reverb.php');

            $this->assertSame(
                ['127.0.0.1', 'localhost'],
                $reverb['apps']['apps'][0]['allowed_origins'],
            );
        } finally {
            $this->restoreEnvironmentValue($key, $originalEnvironment, $originalServer, $originalValue);
        }
    }

    public function test_broadcasts_are_queued_after_commit_on_dedicated_queue(): void
    {
        $event = new EventLifecycleUpdated(1, AuditAction::EventUpdated);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertTrue($event->afterCommit);
        $this->assertSame('broadcasts', $event->broadcastQueue());
    }

    public function test_event_lifecycle_reaches_global_and_event_operational_channels(): void
    {
        $channels = (new EventLifecycleUpdated(41, AuditAction::EventUpdated))->broadcastOn();

        $this->assertSame(
            ['private-events', 'private-events.41'],
            array_map(fn ($channel): string => $channel->name, $channels),
        );
    }

    #[DataProvider('finalMasterClientEvents')]
    public function test_final_master_events_are_subscribed_by_the_realtime_client(string $eventName): void
    {
        $this->assertStringContainsString("'{$eventName}'", $this->javascript());
    }

    public function test_realtime_refresh_uses_a_trailing_request_instead_of_dropping_concurrent_events(): void
    {
        $javascript = $this->javascript();

        $this->assertStringContainsString('component.refreshPending = true;', $javascript);
        $this->assertStringContainsString('component.refreshPending = false;', $javascript);
        $this->assertStringContainsString(
            'window.queueMicrotask(() => component[method]());',
            $javascript,
        );
    }

    public function test_app_and_tv_monitor_refresh_after_a_reverb_reconnection(): void
    {
        $javascript = $this->javascript();
        $appLayout = $this->file(resource_path('views/layouts/app.blade.php'));
        $monitorLayout = $this->file(resource_path('views/layouts/monitor.blade.php'));

        $this->assertStringContainsString(
            "new CustomEvent('sehat:realtime-connected')",
            $javascript,
        );
        $this->assertStringContainsString("window.addEventListener('sehat:realtime-connected'", $javascript);
        $this->assertStringContainsString('x-data="realtimeStatus"', $appLayout);
        $this->assertStringContainsString('x-data="realtimeStatus"', $monitorLayout);
    }

    public function test_realtime_client_does_not_poll_or_force_a_page_reload(): void
    {
        $javascript = $this->javascript();

        $this->assertStringNotContainsString('location.reload', $javascript);
        $this->assertStringNotContainsString('window.location.reload', $javascript);
    }

    public function test_check_in_availability_and_event_state_refresh_in_place(): void
    {
        $javascript = $this->javascript();
        $view = $this->file(resource_path('views/check-ins/index.blade.php'));

        $this->assertStringContainsString(
            "channel?.listen('.service-post.updated', () => this.refreshTickets());",
            $javascript,
        );
        $this->assertStringContainsString(
            "channel?.listen('.event.lifecycle.updated', () => this.refreshTickets());",
            $javascript,
        );
        $this->assertStringContainsString('this.applyWorkflow(payload.meta.workflow);', $javascript);
        $this->assertStringContainsString(':disabled="! eventActive || ! serviceAvailable(', $view);
        $this->assertStringContainsString('! eventActive || ! hasAvailableService', $view);
    }

    public function test_all_operational_compatibility_forms_submit_asynchronously(): void
    {
        foreach ([
            resource_path('views/screening/index.blade.php'),
            resource_path('views/health/index.blade.php'),
            resource_path('views/health/assessment.blade.php'),
            resource_path('views/components/donor-queue-column.blade.php'),
        ] as $path) {
            $view = $this->file($path);

            $this->assertSame(
                substr_count($view, '<form'),
                substr_count($view, 'data-realtime-submit'),
                "{$path} still contains a synchronous operational form.",
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function finalMasterClientEvents(): iterable
    {
        yield 'participant registered' => ['.participant.registered'];
        yield 'participant moved to eligibility' => ['.participant.moved-to-eligibility'];
        yield 'participant eligible' => ['.participant.eligible'];
        yield 'participant ineligible' => ['.participant.ineligible'];
        yield 'participant moved to donation' => ['.participant.moved-to-donation'];
        yield 'participant donation completed' => ['.participant.donation-completed'];
        yield 'participant moved to health check' => ['.participant.moved-to-health-check'];
        yield 'participant health check completed' => ['.participant.health-check-completed'];
        yield 'queue updated' => ['.queue.updated'];
        yield 'dashboard updated' => ['.dashboard.updated'];
        yield 'TV monitor updated' => ['.tv-monitor.updated'];
    }

    private function javascript(): string
    {
        return $this->file(resource_path('js/app.js'));
    }

    private function file(string $path): string
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        return $contents;
    }

    private function restoreEnvironmentValue(
        string $key,
        ?string $environment,
        ?string $server,
        string|false $value,
    ): void {
        if ($environment === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $environment;
        }

        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }

        putenv($value === false ? $key : "{$key}={$value}");
    }
}
