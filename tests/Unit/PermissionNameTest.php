<?php

namespace Tests\Unit;

use App\Enums\PermissionName;
use PHPUnit\Framework\TestCase;

class PermissionNameTest extends TestCase
{
    public function test_permission_values_are_unique(): void
    {
        $values = PermissionName::values();

        $this->assertSame($values, array_values(array_unique($values)));
    }
}
