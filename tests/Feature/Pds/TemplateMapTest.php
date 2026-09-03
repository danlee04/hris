<?php

namespace Tests\Feature\Pds;

use App\Services\Pds\TemplateMap;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TemplateMapTest extends TestCase
{
    private function map(): TemplateMap
    {
        return app(TemplateMap::class);
    }

    public function test_the_template_file_is_where_the_map_says_it_is(): void
    {
        // Everything else in this phase depends on it. Fail here, clearly,
        // rather than three layers down inside PhpSpreadsheet.
        $this->assertFileExists($this->map()->path());
    }

    public function test_a_missing_path_is_a_loud_failure(): void
    {
        // A typo in a dot path must not silently write nothing. On a form of
        // 150 fields, one quietly empty cell is invisible.
        $this->expectException(InvalidArgumentException::class);

        $this->map()->cell('personal_information.cells.no_such_field');
    }

    public function test_it_resolves_a_real_cell(): void
    {
        $this->assertSame('D10', $this->map()->cell('personal_information.cells.surname'));
        $this->assertSame('C1', $this->map()->sheet('page_1'));
    }

    public function test_every_sheet_named_in_the_map_exists_in_the_template(): void
    {
        // Guards against the CSC renaming a continuation sheet in a later
        // revision, which would otherwise surface as a null worksheet.
        $book = IOFactory::load($this->map()->path());

        foreach (config('pds_template.sheets') as $key => $name) {
            $this->assertNotNull(
                $book->getSheetByName($name),
                "The template has no sheet named [{$name}] for key [{$key}]."
            );
        }
    }

    public function test_every_mapped_cell_is_a_valid_reference(): void
    {
        // Catches 'D1O' where 'D10' was meant — a letter O for a zero reads
        // the same and writes into a column that does not exist.
        foreach (config('pds_template.personal_information.cells') as $field => $reference) {
            $this->assertMatchesRegularExpression(
                '/^[A-Z]{1,3}\d+$/',
                $reference,
                "[{$field}] maps to [{$reference}], which is not an A1 reference."
            );
        }
    }

    public function test_the_personal_information_cells_are_all_inside_page_one(): void
    {
        // A reference that drifted onto another sheet's coordinates would write
        // into an empty area and produce a form that prints blank.
        $book = IOFactory::load($this->map()->path());
        $sheet = $book->getSheetByName($this->map()->sheet('page_1'));
        $highestRow = $sheet->getHighestRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        foreach ($this->map()->cells('personal_information') as $field => $reference) {
            [$column, $row] = Coordinate::coordinateFromString($reference);

            $this->assertLessThanOrEqual($highestRow, (int) $row, "[{$field}] is past the last row.");
            $this->assertLessThanOrEqual(
                $highestColumn,
                Coordinate::columnIndexFromString($column),
                "[{$field}] is past the last column."
            );
        }
    }

    public function test_no_two_fields_share_a_cell(): void
    {
        // Two fields on one cell means the second silently overwrites the
        // first, and the form loses a value with no sign of it.
        $cells = array_merge(
            array_values($this->map()->cells('personal_information')),
            array_values($this->map()->cells('family_background')),
        );

        $this->assertSame(
            count($cells),
            count(array_unique($cells)),
            'Two fields are mapped to the same cell: '
                .implode(', ', array_keys(array_filter(array_count_values($cells), fn ($n) => $n > 1)))
        );
    }
}
