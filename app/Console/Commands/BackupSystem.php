<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseDump;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * One archive holding the database and the files it points at.
 *
 * There was no backup at all before this. The hospital's PDS records — dates of
 * birth, TIN, GSIS, home addresses, and the answers to items 34 to 40 — sat on
 * one disk in one room, and a failed drive would have ended them.
 *
 * One file per run, named for the moment it was taken, so copying a backup off
 * the machine is a drag rather than a procedure.
 */
class BackupSystem extends Command
{
    protected $signature = 'hris:backup
                            {--path= : Where to write it, overriding config}
                            {--keep= : How many days of archives to keep}';

    protected $description = 'Write the database and uploaded files to one dated archive';

    public function handle(DatabaseDump $dump): int
    {
        $path = $this->option('path') ?: config('backup.path');
        $keep = (int) ($this->option('keep') ?: config('backup.keep_days'));

        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            $this->error("Cannot create [{$path}].");

            return self::FAILURE;
        }

        $stamp = Carbon::now()->format('Y-m-d-Hi');
        $archive = rtrim($path, '/\\').DIRECTORY_SEPARATOR."hris-{$stamp}.zip";
        $sql = sys_get_temp_dir().DIRECTORY_SEPARATOR."hris-{$stamp}.sql";

        try {
            $this->line('Dumping the database...');
            $dump->write($sql);

            $this->line('Packing the archive...');
            $files = $this->pack($archive, $sql);
        } catch (RuntimeException $e) {
            @unlink($sql);

            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($sql);
        }

        $this->newLine();
        $this->info(sprintf('%s — %s, %d files besides the database.',
            basename($archive), $this->size($archive), $files));

        // Only after a run that worked. Pruning first would let a backup that
        // has been failing for a fortnight quietly eat the last good one.
        $removed = $this->prune($path, $keep);

        if ($removed > 0) {
            $this->line(sprintf('%d archives older than %d days removed.', $removed, $keep));
        }

        return self::SUCCESS;
    }

    /**
     * @return int how many files went in besides the database
     */
    private function pack(string $archive, string $sql): int
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write [{$archive}].");
        }

        $zip->addFile($sql, 'database.sql');

        $count = 0;

        foreach (config('backup.include') as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $finder = (new Finder)->files()->in($directory)->ignoreDotFiles(false);

            foreach (config('backup.exclude') as $exclude) {
                $finder->exclude($exclude);
            }

            foreach ($finder as $file) {
                $zip->addFile(
                    $file->getRealPath(),
                    'files/'.basename($directory).'/'.$file->getRelativePathname()
                );

                $count++;
            }
        }

        $zip->close();

        return $count;
    }

    /** @return int how many archives were removed */
    private function prune(string $path, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($keep)->getTimestamp();
        $removed = 0;

        foreach (glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'hris-*.zip') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function size(string $file): string
    {
        $bytes = filesize($file) ?: 0;

        return $bytes > 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, round($bytes / 1024)).' KB';
    }
}
