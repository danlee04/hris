<?php

namespace Tests\Feature\Leave;

use App\Services\Leave\Form6Map;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

class Form6MapTest extends TestCase
{
    public function test_the_template_is_where_the_map_says_it_is(): void
    {
        $this->assertFileExists(app(Form6Map::class)->path());
    }

    public function test_the_sheet_named_in_the_map_is_in_the_workbook(): void
    {
        // A renamed sheet is the failure that would otherwise surface as a
        // null worksheet three layers down.
        $map = app(Form6Map::class);

        $book = IOFactory::load($map->path());

        $this->assertNotNull($book->getSheetByName($map->sheet()));

        $book->disconnectWorksheets();
    }

    public function test_every_checkbox_the_map_names_is_linked_in_the_template(): void
    {
        // This is the guard that catches an edit to the form. A tick written to
        // a cell no checkbox listens to is a leave type that silently prints
        // unticked, on a document somebody signs.
        $zip = new ZipArchive;
        $zip->open(app(Form6Map::class)->path());
        $vml = $zip->getFromName('xl/drawings/vmlDrawing1.vml');
        $zip->close();

        foreach (config('form6_template.ticks') as $group => $options) {
            foreach ($options as $option => $cell) {
                $column = preg_replace('/\d/', '', $cell);
                $row = preg_replace('/\D/', '', $cell);

                $this->assertStringContainsString(
                    "<x:FmlaLink>\${$column}\${$row}</x:FmlaLink>",
                    $vml,
                    "{$group}.{$option} points at {$cell}, which no checkbox is linked to"
                );
            }
        }
    }

    public function test_every_cell_the_map_names_is_inside_the_printed_area(): void
    {
        // Everything except the ticks, which live in R and T on purpose. A
        // value written past column J never reaches the paper.
        foreach (config('form6_template.cells') as $key => $cell) {
            $column = preg_replace('/\d/', '', $cell);

            $this->assertLessThanOrEqual('J', $column, "{$key} at {$cell} would not print");
        }

        foreach (config('form6_template.captions') as $key => $caption) {
            $column = preg_replace('/\d/', '', $caption['cell']);

            $this->assertLessThanOrEqual('J', $column, "{$key} at {$caption['cell']} would not print");
        }
    }

    public function test_every_caption_format_has_somewhere_to_put_the_value(): void
    {
        // A format without :value writes the caption back over itself and the
        // field prints blank.
        foreach (config('form6_template.captions') as $key => $caption) {
            $this->assertStringContainsString(':value', $caption['format'], "{$key} has no :value");
        }
    }

    public function test_every_leave_type_in_the_seeder_has_a_box(): void
    {
        // Except Wellness, which has no box on the form and prints on the
        // "Others:" line.
        $codes = collect(config('form6_template.ticks.types'))->keys();

        foreach (['VL', 'FL', 'SL', 'ML', 'PL', 'SPL', 'SOLO', 'STUDY', 'VAWC', 'REHAB', 'SLBW', 'CALAMITY', 'ADOPTION'] as $code) {
            $this->assertTrue($codes->contains($code), "{$code} has no box");
        }

        $this->assertFalse($codes->contains('WELLNESS'));
    }

    public function test_a_missing_cell_key_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Form6Map::class)->cell('no_such_field');
    }

    public function test_an_unknown_tick_option_is_null_rather_than_an_error(): void
    {
        // An unanswered question ticks nothing. That is not a failure.
        $this->assertNull(app(Form6Map::class)->tick('commutation', 'unanswered'));
        $this->assertNull(app(Form6Map::class)->tick('commutation', null));
    }
}
