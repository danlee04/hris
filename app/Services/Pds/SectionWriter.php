<?php

namespace App\Services\Pds;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Writes one repeating PDS section down its printed rows, and puts whatever
 * does not fit onto the continuation sheet the CSC already supplies.
 *
 * The continuation sheets are not something this code creates. C5 to C11 come
 * with the template, and the form itself says so at the foot of each section:
 * "(Continue on sheet C7 if necessary)".
 *
 * Every section carries its own column map for the continuation, because the
 * columns genuinely differ. A child's date of birth is in M on page 1 and in L
 * on C7; a licence number is in L on page 2 and in J on C9. Assuming they
 * match would file the licence number under "place of examination".
 */
class SectionWriter
{
    public function __construct(private readonly CellWriter $writer, private readonly TemplateMap $map) {}

    /**
     * @param  list<array<string, mixed>>  $rows  each keyed by field name
     * @return int how many rows went to the continuation sheet
     */
    public function write(Spreadsheet $book, string $sectionKey, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $section = $this->map->section($sectionKey);

        $onPage = array_slice($rows, 0, $section['row_count']);
        $overflow = array_slice($rows, $section['row_count']);

        $this->fill(
            $book,
            $this->map->sheet($section['sheet']),
            $section['first_row'],
            $section['columns'],
            $onPage,
        );

        if ($overflow === []) {
            return 0;
        }

        $continuation = $section['continuation'];

        $this->fill(
            $book,
            $this->map->sheet($continuation['sheet']),
            $continuation['first_row'],
            $continuation['columns'],
            array_slice($overflow, 0, $continuation['row_count']),
        );

        return count($overflow);
    }

    /**
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function fill(
        Spreadsheet $book,
        string $sheetName,
        int $firstRow,
        array $columns,
        array $rows,
    ): void {
        $sheet = $book->getSheetByName($sheetName);

        foreach (array_values($rows) as $offset => $row) {
            $line = $firstRow + $offset;

            foreach ($columns as $field => $column) {
                $this->writer->put($sheet, $column.$line, $row[$field] ?? null);
            }
        }
    }
}
