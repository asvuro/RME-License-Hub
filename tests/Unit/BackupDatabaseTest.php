<?php

namespace Tests\Unit;

use App\Console\Commands\BackupDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for `hub:backup-database` — the backup gap this session closed
 * (there was previously NO backup mechanism at all).
 *
 * The local test suite runs on sqlite (phpunit.xml), so the actual
 * mysqldump path can't run here — instead this verifies (1) the command
 * degrades gracefully on a non-mysql connection instead of crashing, and
 * (2) the pruning logic in isolation, since an off-by-one there is exactly
 * the kind of bug that silently deletes backups nobody notices until the
 * day they're needed.
 */
class BackupDatabaseTest extends TestCase
{
    public function test_gracefully_skips_on_a_non_mysql_connection(): void
    {
        $exitCode = Artisan::call('hub:backup-database');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Skipping', Artisan::output());
    }

    public function test_prune_keeps_the_newest_backup_even_if_it_is_older_than_retention(): void
    {
        config(['license.backup_retention_days' => 7]);
        $dir = sys_get_temp_dir().'/hub-backup-test-'.uniqid();
        mkdir($dir, 0700, true);

        // All backups are older than the 7-day retention window — the
        // pruner must still keep the single newest one no matter what.
        $old1 = $dir.'/db-20260101-000000.sql.gz';
        $old2 = $dir.'/db-20260102-000000.sql.gz';
        $newest = $dir.'/db-20260103-000000.sql.gz';
        file_put_contents($old1, 'x');
        file_put_contents($old2, 'x');
        file_put_contents($newest, 'x');
        touch($old1, now()->subDays(100)->timestamp);
        touch($old2, now()->subDays(90)->timestamp);
        touch($newest, now()->subDays(80)->timestamp);

        $command = new BackupDatabase;
        $method = new \ReflectionMethod($command, 'prune');
        $method->setAccessible(true);
        // prune() calls $this->info(); give it a real output target.
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));
        $method->invoke($command, $dir);

        $this->assertFileDoesNotExist($old1);
        $this->assertFileDoesNotExist($old2);
        $this->assertFileExists($newest, 'The single newest backup must never be pruned, regardless of its age.');

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_prune_keeps_backups_within_the_retention_window(): void
    {
        config(['license.backup_retention_days' => 30]);
        $dir = sys_get_temp_dir().'/hub-backup-test-'.uniqid();
        mkdir($dir, 0700, true);

        $recent = $dir.'/db-recent.sql.gz';
        $newest = $dir.'/db-newest.sql.gz';
        file_put_contents($recent, 'x');
        file_put_contents($newest, 'x');
        touch($recent, now()->subDays(5)->timestamp);
        touch($newest, now()->subDay()->timestamp);

        $command = new BackupDatabase;
        $method = new \ReflectionMethod($command, 'prune');
        $method->setAccessible(true);
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));
        $method->invoke($command, $dir);

        $this->assertFileExists($recent, 'Backups within the retention window must survive pruning.');
        $this->assertFileExists($newest);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    private function readyCommand(): BackupDatabase
    {
        $command = new BackupDatabase;
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));

        return $command;
    }

    public function test_offsite_sync_is_skipped_when_bucket_is_not_configured(): void
    {
        config(['filesystems.disks.backup_offsite.bucket' => null]);
        Storage::fake('backup_offsite');

        $tmp = tempnam(sys_get_temp_dir(), 'hub-backup-');
        file_put_contents($tmp, 'fake dump content');

        $command = $this->readyCommand();
        $method = new \ReflectionMethod($command, 'syncOffsite');
        $method->setAccessible(true);
        $method->invoke($command, $tmp, 'db-test.sql.gz');

        Storage::disk('backup_offsite')->assertMissing('hub-backups/db-test.sql.gz');
        unlink($tmp);
    }

    public function test_offsite_sync_uploads_the_backup_when_configured(): void
    {
        config(['filesystems.disks.backup_offsite.bucket' => 'test-bucket']);
        Storage::fake('backup_offsite');

        $tmp = tempnam(sys_get_temp_dir(), 'hub-backup-');
        file_put_contents($tmp, 'fake dump content');

        $command = $this->readyCommand();
        $method = new \ReflectionMethod($command, 'syncOffsite');
        $method->setAccessible(true);
        $method->invoke($command, $tmp, 'db-test.sql.gz');

        Storage::disk('backup_offsite')->assertExists('hub-backups/db-test.sql.gz');
        $this->assertSame('fake dump content', Storage::disk('backup_offsite')->get('hub-backups/db-test.sql.gz'));
        unlink($tmp);
    }

    public function test_offsite_prune_keeps_the_newest_backup_even_if_older_than_retention(): void
    {
        config(['filesystems.disks.backup_offsite.bucket' => 'test-bucket']);
        config(['license.backup_retention_days' => 7]);
        Storage::fake('backup_offsite');
        $disk = Storage::disk('backup_offsite');

        $disk->put('hub-backups/old1.sql.gz', 'x');
        $disk->put('hub-backups/old2.sql.gz', 'x');
        $disk->put('hub-backups/newest.sql.gz', 'x');
        // Flysystem's local fake driver uses real file mtimes — backdate the
        // two "old" ones so they fall outside the 7-day retention window.
        touch($disk->path('hub-backups/old1.sql.gz'), now()->subDays(100)->timestamp);
        touch($disk->path('hub-backups/old2.sql.gz'), now()->subDays(90)->timestamp);
        touch($disk->path('hub-backups/newest.sql.gz'), now()->subDays(80)->timestamp);

        $command = $this->readyCommand();
        $method = new \ReflectionMethod($command, 'pruneOffsite');
        $method->setAccessible(true);
        $method->invoke($command, $disk);

        $disk->assertMissing('hub-backups/old1.sql.gz');
        $disk->assertMissing('hub-backups/old2.sql.gz');
        $disk->assertExists('hub-backups/newest.sql.gz');
    }
}
