<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Models\Event;
use App\Models\ServicePost;
use App\Models\User;
use Closure;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RealtimeChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('globalEventChannelPermissions')]
    public function test_global_event_channel_authorizes_each_subscribing_ui(
        PermissionName $permission,
    ): void {
        $user = $this->userWithPermission($permission);

        $this->assertTrue($this->channelAuthorizer('events')($user));
    }

    #[DataProvider('eventChannelPermissions')]
    public function test_event_channel_authorizes_each_subscribing_ui(
        PermissionName $permission,
    ): void {
        $user = $this->userWithPermission($permission);
        $event = Event::factory()->create();

        $this->assertTrue($this->channelAuthorizer('events.{eventId}')($user, $event->id));
    }

    public function test_realtime_channels_reject_unrelated_permissions(): void
    {
        $eventChannelUser = $this->userWithPermission(PermissionName::ManageParticipants);
        $globalChannelUser = $this->userWithPermission(PermissionName::ManageCheckIn);
        $event = Event::factory()->create();

        $this->assertFalse($this->channelAuthorizer('events.{eventId}')($eventChannelUser, $event->id));
        $this->assertFalse($this->channelAuthorizer('events')($globalChannelUser));
    }

    public function test_own_queue_operator_is_limited_to_assigned_event_posts(): void
    {
        $operator = $this->userWithPermission(PermissionName::ManageOwnQueue);
        $assignedEvent = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $assignedPost = ServicePost::factory()->for($assignedEvent)->create();
        $assignedPost->operators()->attach($operator);
        $authorize = $this->channelAuthorizer('events.{eventId}');

        $this->assertTrue($authorize($operator, $assignedEvent->id));
        $this->assertFalse($authorize($operator, $otherEvent->id));
    }

    public function test_event_channel_rejects_a_nonexistent_event(): void
    {
        $eventManager = $this->userWithPermission(PermissionName::ManageEvents);

        $this->assertFalse($this->channelAuthorizer('events.{eventId}')($eventManager, PHP_INT_MAX));
    }

    public function test_broadcast_auth_endpoint_enforces_the_event_channel_contract(): void
    {
        config()->set([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'realtime-test-key',
            'broadcasting.connections.reverb.secret' => 'realtime-test-secret',
            'broadcasting.connections.reverb.app_id' => 'realtime-test-app',
        ]);
        require base_path('routes/channels.php');

        $event = Event::factory()->create();
        $authorized = $this->userWithPermission(PermissionName::ViewDashboard);
        $unauthorized = $this->userWithPermission(PermissionName::ManageParticipants);
        $payload = [
            'socket_id' => '1234.5678',
            'channel_name' => "private-events.{$event->id}",
        ];

        $authorizedResponse = $this->actingAs($authorized)
            ->postJson('/broadcasting/auth', $payload);
        $authorizedResponse->assertOk();
        $authorization = json_decode($authorizedResponse->getContent(), true);
        $this->assertIsArray($authorization);
        $this->assertArrayHasKey('auth', $authorization);

        $this->actingAs($unauthorized)
            ->postJson('/broadcasting/auth', $payload)
            ->assertForbidden();
    }

    public function test_participant_channel_remains_limited_to_participant_managers(): void
    {
        $participantManager = $this->userWithPermission(PermissionName::ManageParticipants);
        $eventManager = $this->userWithPermission(PermissionName::ManageEvents);

        $this->assertTrue($this->channelAuthorizer('participants')($participantManager));
        $this->assertFalse($this->channelAuthorizer('participants')($eventManager));
    }

    /**
     * @return iterable<string, array{PermissionName}>
     */
    public static function globalEventChannelPermissions(): iterable
    {
        yield 'event manager' => [PermissionName::ManageEvents];
        yield 'dashboard' => [PermissionName::ViewDashboard];
        yield 'monitor' => [PermissionName::ViewMonitor];
    }

    /**
     * @return iterable<string, array{PermissionName}>
     */
    public static function eventChannelPermissions(): iterable
    {
        yield 'dashboard' => [PermissionName::ViewDashboard];
        yield 'service post manager' => [PermissionName::ManageServicePosts];
        yield 'check-in operator' => [PermissionName::ManageCheckIn];
        yield 'health operator' => [PermissionName::ManageHealth];
        yield 'screening operator' => [PermissionName::ManageScreening];
        yield 'donation operator' => [PermissionName::ManageDonation];
        yield 'monitor viewer' => [PermissionName::ViewMonitor];
        yield 'report viewer' => [PermissionName::ViewReports];
    }

    private function userWithPermission(PermissionName $permission): User
    {
        Permission::query()->firstOrCreate([
            'name' => $permission->value,
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo($permission->value);

        return $user;
    }

    /**
     * @return Closure(User): bool
     */
    private function channelAuthorizer(string $channel): Closure
    {
        $authorizer = app(BroadcastManager::class)
            ->driver()
            ->getChannels()
            ->get($channel);

        $this->assertInstanceOf(Closure::class, $authorizer);

        return $authorizer;
    }
}
