<?php

namespace App\Services\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Writes the database to a plain SQL file.
 *
 * `mysqldump` rather than a PHP loop over every table: it is what the DBA
 * documentation of every hosting provider assumes, it is what restores with
 * `mysql < file.sql` on any machine, and it does not need this application to
 * be installed or even to run.
 */
class DatabaseDump
{
    /** Big enough for a hospital's records, small enough to notice a hang. */
    private const TIMEOUT_SECONDS = 900;

    public function write(string $to): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            // Refused rather than skipped. An archive containing the uploads
            // and no database would restore as an empty hospital, and the file
            // beside it would say "backup".
            throw new RuntimeException(
                "Only a MySQL connection can be dumped; [{$connection}] is not one."
            );
        }

        $config = config("database.connections.{$connection}");

        $process = new Process([
            $this->binary(),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            // Single-transaction so the dump is consistent without locking the
            // hospital out of its own system while it runs.
            '--single-transaction',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--result-file='.$to,
            $config['database'],
        ], timeout: self::TIMEOUT_SECONDS);

        // The password goes in the environment, not in the argument list. On
        // the command line it is visible to anybody who can run `ps`, and on
        // Windows it lands in the process table just the same.
        $process->setEnv(['MYSQL_PWD' => (string) $config['password']]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }
    }

    /** Overridable because Laragon does not put mysqldump on the PATH. */
    private function binary(): string
    {
        return config('backup.mysqldump', 'mysqldump');
    }
}
