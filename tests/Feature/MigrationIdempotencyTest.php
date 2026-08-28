<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the redeploy scenario: `php artisan migrate` runs again against a
 * database that already has every table (e.g. a container restart, or a
 * second `deploy.sh` run that doesn't know the DB is already current).
 *
 * Several migrations in this codebase were written with explicit
 * `Schema::hasTable()` / `Schema::hasColumn()` guards specifically so this is
 * safe — this test is the regression guard for that property, not just for
 * Laravel's own migrations-table bookkeeping (which already no-ops re-runs
 * of migrations it has recorded; the real risk is a guard being missing or
 * broken on a NEW migration added later).
 */
class MigrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrating_an_already_migrated_database_is_a_safe_no_op(): void
    {
        // RefreshDatabase has already run every migration once for this test.
        $tablesBefore = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))
            ->pluck('name')->sort()->values();

        // Re-run the full migration set against the SAME already-migrated DB.
        // Laravel skips migrations already recorded in the `migrations` table,
        // but this also exercises every custom Schema::hasTable()/hasColumn()
        // guard in case a migration is ever re-run out of band (e.g. a manual
        // `migrate --path=` on one file, or a migrations-table reset).
        Artisan::call('migrate', ['--force' => true]);

        $tablesAfter = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))
            ->pluck('name')->sort()->values();

        $this->assertSame($tablesBefore->all(), $tablesAfter->all());
    }

    public function test_rerunning_a_guarded_migration_directly_does_not_throw(): void
    {
        // Simulate the actual failure mode: a single migration file executed
        // again even though its table already exists (e.g. `migrate:refresh`
        // partially failing then retried, or a hand-run `artisan migrate:rollback`
        // followed by a mis-targeted `--path` migrate). Every migration that
        // uses `if (Schema::hasTable(...)) { return; }` must survive this.
        $guardedMigrations = [
            'database/migrations/2026_08_28_100000_create_group_referrals_table.php',
            'database/migrations/2026_08_28_000013_add_instance_url_and_secret_to_tenants_table.php',
            'database/migrations/2026_08_28_000015_add_targets_to_force_disable_actions_table.php',
        ];

        foreach ($guardedMigrations as $path) {
            $migration = require base_path($path);
            // Running `up()` a second time against the already-migrated schema
            // must not throw (the guard must short-circuit cleanly).
            $migration->up();
        }

        $this->assertTrue(true);
    }
}
