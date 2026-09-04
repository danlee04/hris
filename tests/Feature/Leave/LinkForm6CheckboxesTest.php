<?php

namespace Tests\Feature\Leave;

use App\Console\Commands\LinkForm6Checkboxes;
use Tests\TestCase;
use ZipArchive;

class LinkForm6CheckboxesTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_readable(storage_path('app/'.LinkForm6Checkboxes::SOURCE))) {
            $this->markTestSkipped('The DTRC CS Form 6 template is not in storage/app/templates.');
        }

        $this->target = storage_path('app/templates/test-linked.xlsx');
    }

    protected function tearDown(): void
    {
        if (is_file($this->target)) {
            unlink($this->target);
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private function linksIn(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $vml = $zip->getFromName('xl/drawings/vmlDrawing1.vml');
        $zip->close();

        preg_match_all('~<x:FmlaLink>\$([A-Z]+)\$(\d+)</x:FmlaLink>~', $vml, $matches, PREG_SET_ORDER);

        return array_map(fn ($m) => $m[1].$m[2], $matches);
    }

    public function test_it_links_every_checkbox_on_the_form(): void
    {
        $this->artisan('form6:link', ['--target' => $this->target])->assertSuccessful();

        $this->assertCount(25, $this->linksIn($this->target));
    }

    public function test_the_leave_types_land_in_column_r_and_the_rest_in_column_t(): void
    {
        // The two panels overlap in rows: "Vacation Leave" and "Within the
        // Philippines" share row 17. One column for both would tick a leave
        // type when somebody answered a question about their destination.
        $this->artisan('form6:link', ['--target' => $this->target]);

        $links = $this->linksIn($this->target);

        $this->assertCount(13, array_filter($links, fn ($cell) => str_starts_with($cell, 'R')));
        $this->assertCount(12, array_filter($links, fn ($cell) => str_starts_with($cell, 'T')));
    }

    public function test_the_links_sit_outside_the_print_area(): void
    {
        // The print area is $A$2:$J$69. A link inside it would put TRUE and
        // FALSE on the signed form.
        $this->artisan('form6:link', ['--target' => $this->target]);

        foreach ($this->linksIn($this->target) as $cell) {
            $column = preg_replace('/\d/', '', $cell);

            $this->assertGreaterThan('J', $column, "{$cell} would print");
        }
    }

    public function test_the_original_is_left_alone(): void
    {
        $source = storage_path('app/'.LinkForm6Checkboxes::SOURCE);
        $before = md5_file($source);

        $this->artisan('form6:link', ['--target' => $this->target]);

        $this->assertSame($before, md5_file($source));
    }

    public function test_it_refuses_to_write_over_its_own_source(): void
    {
        // Writing over the original would destroy the only copy of the form the
        // hospital actually maintains.
        $source = storage_path('app/'.LinkForm6Checkboxes::SOURCE);
        $before = md5_file($source);

        $this->artisan('form6:link', ['--target' => $source])->assertFailed();

        $this->assertSame($before, md5_file($source));
    }

    public function test_it_refuses_a_source_that_is_not_there(): void
    {
        $this->artisan('form6:link', [
            '--source' => storage_path('app/templates/no-such-file.xlsx'),
            '--target' => $this->target,
        ])->assertFailed();
    }
}
