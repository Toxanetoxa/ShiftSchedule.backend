<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('app:initialize')]
#[Description('Apply migrations and idempotent seed data before starting the application')]
class InitializeApplication extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('ShiftSchedule requires PostgreSQL.');

            return self::FAILURE;
        }

        $connection->select('SELECT pg_advisory_lock(1839, 1109)');
        try {
            if ($this->call('migrate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            $connection->transaction(function (): void {
                if ($this->call('db:seed', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                    throw new RuntimeException('Database seed failed.');
                }
            });
        } finally {
            $connection->select('SELECT pg_advisory_unlock(1839, 1109)');
        }

        return self::SUCCESS;
    }
}
