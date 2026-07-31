<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    public function test_sqlite_backup_command_writes_a_verified_archive(): void
    {
        Storage::fake('local');
        config()->set('backup.backup.destination.disks', ['local']);
        config()->set('backup.backup.name', 'SEHAT-APP');
        config()->set('backup.backup.password', null);

        $this->artisan('app:backup --only-db --disable-notifications')
            ->assertSuccessful();

        $files = Storage::disk('local')->allFiles('SEHAT-APP');

        $this->assertCount(1, $files);
        Storage::disk('local')->assertExists($files[0]);
    }
}
