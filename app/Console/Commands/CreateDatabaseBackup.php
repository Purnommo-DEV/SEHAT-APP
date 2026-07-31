<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class CreateDatabaseBackup extends Command
{
    protected $signature = 'app:backup
        {--disable-notifications : Do not send backup notifications}
        {--only-db : Keep the command name compatible with the scheduled database backup}';

    protected $description = 'Create a verified database backup using the configured backup disk';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'sqlite') {
            return $this->runSpatieBackup();
        }

        return $this->createSqliteBackup();
    }

    private function runSpatieBackup(): int
    {
        $exitCode = Artisan::call('backup:run', [
            '--only-db' => true,
            '--disable-notifications' => $this->option('disable-notifications'),
        ], $this->output);

        return $exitCode;
    }

    private function createSqliteBackup(): int
    {
        $diskName = (string) config('backup.backup.destination.disks.0', 'local');
        $backupName = (string) config('backup.backup.name', config('app.name'));
        $temporaryDirectory = (string) config('backup.backup.temporary_directory', storage_path('app/backup-temp'));
        $temporaryDatabase = $temporaryDirectory.'/database-'.bin2hex(random_bytes(8)).'.sqlite';
        $temporaryArchive = $temporaryDirectory.'/backup-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';

        File::ensureDirectoryExists($temporaryDirectory);

        try {
            $quotedPath = DB::getPdo()->quote($temporaryDatabase);
            DB::statement("VACUUM INTO {$quotedPath}");
            $this->createArchive($temporaryDatabase, $temporaryArchive);

            $disk = Storage::disk($diskName);
            $destination = $backupName.'/'.basename($temporaryArchive);
            $stream = fopen($temporaryArchive, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Backup archive could not be opened.');
            }

            try {
                $disk->put($destination, $stream);
            } finally {
                fclose($stream);
            }

            $this->info("SQLite backup written to {$diskName}:{$destination}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('SQLite backup failed: '.$exception->getMessage());

            report($exception);

            return self::FAILURE;
        } finally {
            File::delete([$temporaryDatabase, $temporaryArchive]);
        }
    }

    private function createArchive(string $databasePath, string $archivePath): void
    {
        $archive = new ZipArchive;
        $result = $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException("Unable to create backup archive (code {$result}).");
        }

        $archive->addFile($databasePath, 'database.sqlite');

        $password = (string) config('backup.backup.password');

        if ($password !== '') {
            $archive->setPassword($password);
            $archive->setEncryptionName('database.sqlite', ZipArchive::EM_AES_256);
        }

        if (! $archive->close() || ! $this->verifyArchive($archivePath)) {
            throw new RuntimeException('Backup archive verification failed.');
        }
    }

    private function verifyArchive(string $archivePath): bool
    {
        $archive = new ZipArchive;

        if ($archive->open($archivePath) !== true) {
            return false;
        }

        $valid = $archive->locateName('database.sqlite') !== false;
        $archive->close();

        return $valid;
    }
}
