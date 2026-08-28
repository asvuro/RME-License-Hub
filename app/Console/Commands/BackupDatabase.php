<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Daily database backup — the "backup" gap this session closed (there was
 * previously NO backup mechanism at all; the docker-compose `hub-db-data`
 * volume only survives container restarts, not disk/volume loss).
 *
 * Deliberately DOES NOT back up storage/keys/*.pem (RSA license-signing
 * keys) into this same rotating directory — bundling secrets with routine
 * dumps that may get copied around widens the exposure surface. See
 * DEPLOYMENT.md for the separate, manual key-backup procedure (a vault /
 * password manager, not a cron job).
 */
class BackupDatabase extends Command
{
    protected $signature = 'hub:backup-database {--path= : Override the backup directory (default: storage/app/backups)}';

    protected $description = 'Dump the hub database (mysqldump, gzipped) and prune backups older than license.backup_retention_days';

    public function handle(): int
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("hub:backup-database only supports mysql/mariadb (current connection driver: {$driver}). Skipping — nothing to do on sqlite/local dev.");

            return 0;
        }

        $dbConfig = config("database.connections.{$connection}");
        $dir = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0700);

        $filename = sprintf('%s-%s.sql.gz', $dbConfig['database'], now()->format('Ymd-His'));
        $path = $dir.'/'.$filename;

        $dumpCommand = [
            'mysqldump',
            '--host='.$dbConfig['host'],
            '--port='.$dbConfig['port'],
            '--user='.$dbConfig['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            $dbConfig['database'],
        ];

        // Password via env (MYSQL_PWD), never as a CLI argument — a CLI arg
        // is visible to any other local user via `ps`/`/proc`; env vars
        // passed directly to a child process are not.
        $process = Process::fromShellCommandline(
            implode(' ', array_map('escapeshellarg', $dumpCommand)).' | gzip -9 > '.escapeshellarg($path),
            null,
            ['MYSQL_PWD' => $dbConfig['password']],
            null,
            300
        );

        $process->run();

        if (! $process->isSuccessful() || ! File::exists($path) || File::size($path) === 0) {
            @File::delete($path);
            $message = "hub:backup-database FAILED: {$process->getErrorOutput()}";
            $this->error($message);
            Log::error($message);

            return 1;
        }

        File::chmod($path, 0600);
        $sizeKb = round(File::size($path) / 1024, 1);
        $this->info("Backup written: {$path} ({$sizeKb} KB)");
        Log::info("hub:backup-database: wrote {$filename} ({$sizeKb} KB)");

        $this->prune($dir);

        return 0;
    }

    /**
     * Delete backups older than license.backup_retention_days. Never lets a
     * pruning bug leave ZERO backups — always keeps at least the newest one,
     * even if retention is misconfigured to 0 or the clock is wrong.
     */
    private function prune(string $dir): void
    {
        $retentionDays = (int) config('license.backup_retention_days', 30);
        $cutoff = now()->subDays($retentionDays)->timestamp;

        $files = collect(File::files($dir))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql.gz'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        $deleted = 0;
        foreach ($files->slice(1) as $file) { // never delete the newest
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->info("Pruned {$deleted} backup(s) older than {$retentionDays} days.");
        }
    }
}
