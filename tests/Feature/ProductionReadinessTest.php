<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_endpoint_checks_database_and_cache_and_sends_security_headers(): void
    {
        $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true)
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_operational_and_export_routes_are_rate_limited(): void
    {
        $dashboard = Route::getRoutes()->getByName('dashboard');
        $excel = Route::getRoutes()->getByName('reports.excel');
        $pdf = Route::getRoutes()->getByName('reports.pdf');

        $this->assertNotNull($dashboard);
        $this->assertNotNull($excel);
        $this->assertNotNull($pdf);
        $this->assertContains('throttle:operational', $dashboard->gatherMiddleware());
        $this->assertContains('throttle:reports', $excel->gatherMiddleware());
        $this->assertContains('throttle:reports', $pdf->gatherMiddleware());
    }

    public function test_user_content_is_escaped_in_event_pages(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = User::query()->role(UserRole::Administrator->value)->firstOrFail();
        $event = Event::factory()->create([
            'created_by' => $administrator->id,
            'name' => '<script>alert("xss")</script>',
        ]);

        $this->actingAs($administrator)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("xss")</script>', false);
    }

    public function test_admin_seeder_uses_the_fixed_bootstrap_credentials(): void
    {
        config()->set('foundation.seed_admin.email', 'other@example.test');
        config()->set('foundation.seed_admin.password', 'other-password');

        $this->seed(AdminSeeder::class);

        $administrator = User::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();

        $this->assertTrue(Hash::check(AdminSeeder::PASSWORD, $administrator->password));
        $this->assertTrue($administrator->hasRole(UserRole::Administrator->value));
    }

    public function test_maintenance_schedule_contains_backup_monitoring_and_pruning(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('app:backup --only-db', $output);
        $this->assertStringContainsString('backup:clean', $output);
        $this->assertStringContainsString('backup:monitor', $output);
        $this->assertStringContainsString('queue:prune-failed', $output);
    }
}
