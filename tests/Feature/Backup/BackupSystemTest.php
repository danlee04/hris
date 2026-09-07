<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\DatabaseDump;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupSystemTest extends TestCase
{
    private string $destination;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = sys_get_temp_dir().'/hris-backup-test-'.uniqid();
        $this->source = sys_get_temp_dir().'/hris-source-test-'.uniqid();

        mkdir($this->source.'/livewire-tmp', 0755, true);
        file_put_contents($this->source.'/a-real-upload.txt', 'kept');
        file_put_contents($this->source.'/livewire-tmp/half-done.txt', 'dropped');

        config([
            'backup.path' => $this->destination,
            'backup.include' => [$this->source],
            'backup.exclude' => ['livewire-tmp'],
        ]);

        // mysqldump is not available against the in-memory SQLite the suite
        // runs on, and shelling out in a test would be testing MySQL rather
        // than this command.
        $this->swap(DatabaseDump::class, new class extends DatabaseDump
        {
            public function write(string $to): void
            {
                file_put_contents($to, "-- a dump\nCREATE TABLE employees (id int);\n");
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ([$this->destination, $this->source] as $directory) {
            if (is_dir($directory)) {
                $this->remove($directory);
            }
        }

        parent::tearDown();
    }

    private function remove(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $item) {
            is_dir($item) ? $this->remove($item) : unlink($item);
        }

        rmdir($directory);
    }

    private function archives(): array
    {
        return glob($this->destination.'/hris-*.zip') ?: [];
    }

    public function test_it_writes_one_dated_archive(): void
    {
        $this->artisan('hris:backup')->assertSuccessful();

        $this->assertCount(1, $this->archives());
        $this->assertMatchesRegularExpression('/hris-\d{4}-\d{2}-\d{2}-\d{4}\.zip$/', $this->archives()[0]);
    }

    public function test_the_archive_holds_the_database_and_the_files_beside_it(): void
    {
        // A restore with one and not the other is a system full of records
        // pointing at documents that are not there.
        $this->artisan('hris:backup')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->archives()[0]);

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('database.sql', $names);
        $this->assertStringContainsString('CREATE TABLE employees', $zip->getFromName('database.sql'));
        $this->assertTrue((bool) collect($names)->first(fn ($n) => str_contains($n, 'a-real-upload.txt')));

        $zip->close();
    }

    public function test_it_leaves_out_half_finished_uploads(): void
    {
        $this->artisan('hris:backup')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->archives()[0]);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('livewire-tmp', $zip->getNameIndex($i));
        }

        $zip->close();
    }

    public function test_it_creates_the_destination_when_it_is_not_there(): void
    {
        $this->assertDirectoryDoesNotExist($this->destination);

        $this->artisan('hris:backup')->assertSuccessful();

        $this->assertDirectoryExists($this->destination);
    }

    public function test_it_removes_archives_older_than_the_window(): void
    {
        mkdir($this->destination, 0755, true);

        $old = $this->destination.'/hris-2020-01-01-0900.zip';
        $recent = $this->destination.'/hris-2020-01-02-0900.zip';

        file_put_contents($old, 'old');
        file_put_contents($recent, 'recent');
        touch($old, Carbon::now()->subDays(30)->getTimestamp());
        touch($recent, Carbon::now()->subDays(2)->getTimestamp());

        $this->artisan('hris:backup', ['--keep' => 14])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_a_failed_dump_writes_nothing_and_deletes_nothing(): void
    {
        // The whole point of pruning after the run rather than before it: a
        // backup that has been failing for a fortnight must not eat the last
        // good one behind it.
        mkdir($this->destination, 0755, true);

        $old = $this->destination.'/hris-2020-01-01-0900.zip';
        file_put_contents($old, 'the last good one');
        touch($old, Carbon::now()->subDays(30)->getTimestamp());

        $this->swap(DatabaseDump::class, new class extends DatabaseDump
        {
            public function write(string $to): void
            {
                throw new RuntimeException('mysqldump failed: no such host');
            }
        });

        $this->artisan('hris:backup', ['--keep' => 14])
            ->expectsOutputToContain('no such host')
            ->assertFailed();

        $this->assertFileExists($old);
        $this->assertCount(1, $this->archives());
    }
}
