<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OperationalRateLimitTest extends TestCase
{
    public function test_operational_polling_uses_a_separate_read_budget_from_mutations(): void
    {
        $limiter = RateLimiter::limiter('operational');

        $this->assertNotNull($limiter);

        $readLimit = $limiter(Request::create('/dashboard/data', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']));
        $writeLimit = $limiter(Request::create('/events/1/check-ins', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']));

        $this->assertSame(600, $readLimit->maxAttempts);
        $this->assertSame(180, $writeLimit->maxAttempts);
        $this->assertNotSame($readLimit->key, $writeLimit->key);
    }
}
