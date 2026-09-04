<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

/**
 * Gives every checkbox on the DTRC CS Form 6 a linked cell.
 *
 * The template ships with 25 form controls and not one <x:FmlaLink>, so there
 * is no cell to write TRUE into and PhpSpreadsheet can neither create a form
 * control nor tick one. This writes the links into a copy; the original is
 * never touched and stays the thing to fall back to.
 *
 * Run it after every edit to the original. Excel drops the links whenever the
 * linked copy itself is opened and saved, so that file is generated, not
 * maintained.
 */
class LinkForm6Checkboxes extends Command
{
    protected $signature = 'form6:link
                            {--source= : The DTRC form to read}
                            {--target= : The linked copy to write}';

    protected $description = 'Regenerate the linked CS Form 6 template from the original';

    public const SOURCE = 'templates/CS Form No. 6, Revised 2020 (Application for Leave).xlsx';

    public const TARGET = 'templates/CS Form No. 6 (Application for Leave) DTRC linked.xlsx';

    public function handle(): int
    {
        $source = $this->option('source') ?: storage_path('app/'.self::SOURCE);
        $target = $this->option('target') ?: storage_path('app/'.self::TARGET);

        if (! is_readable($source)) {
            $this->error("Cannot read [{$source}].");

            return self::FAILURE;
        }

        if (realpath($source) === realpath($target)) {
            // Writing over the original would destroy the only copy of the form
            // the hospital actually maintains.
            $this->error('The source and the target are the same file.');

            return self::FAILURE;
        }

        if (! copy($source, $target)) {
            $this->error("Cannot write [{$target}].");

            return self::FAILURE;
        }

        $links = $this->link($target);

        if ($links === []) {
            $this->error('No checkboxes were found. Is this the right file?');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(count($links).' checkboxes linked.');
        $this->newLine();

        $this->table(['Cell', 'Beside'], $this->report($target, $links));

        $this->newLine();
        $this->info('Read the second column. A link beside the wrong label ticks the wrong box.');
        $this->line('Do not open the linked copy in Excel: saving from Excel drops the links.');

        return self::SUCCESS;
    }

    /**
     * @return list<string> the cells that were linked
     */
    private function link(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);

        $vml = $zip->getFromName('xl/drawings/vmlDrawing1.vml');

        $cells = [];

        $linked = preg_replace_callback(
            '~<x:ClientData ObjectType="Checkbox">(.*?)</x:ClientData>~s',
            function (array $match) use (&$cells) {
                if (! preg_match('~<x:Anchor>\s*([0-9,\s]+?)</x:Anchor>~s', $match[1], $anchor)) {
                    return $match[0];
                }

                [$leftColumn, , $topRow] = array_map('intval', array_map('trim', explode(',', $anchor[1])));

                // The box is drawn one row above the text it belongs to, every
                // time. Column A holds the leave types and column G the details,
                // the commutation and the recommendation; the links go in two
                // columns because those rows overlap.
                $column = match ($leftColumn) {
                    0 => 'R',
                    6 => 'T',
                    default => null,
                };

                if ($column === null) {
                    return $match[0];
                }

                $row = $topRow + 2;
                $cells[] = $column.$row;

                return '<x:ClientData ObjectType="Checkbox">'.str_replace(
                    '</x:TextVAlign>',
                    "</x:TextVAlign>\n   <x:FmlaLink>\${$column}\${$row}</x:FmlaLink>",
                    $match[1]
                ).'</x:ClientData>';
            },
            $vml
        );

        $zip->addFromString('xl/drawings/vmlDrawing1.vml', $linked);
        $zip->close();

        return $cells;
    }

    /**
     * @param  list<string>  $cells
     * @return list<array{string, string}>
     */
    private function report(string $path, array $cells): array
    {
        // Held in a variable: a worksheet whose parent has been collected
        // returns null for every cell, which reads like a damaged file.
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);

        $rows = [];

        foreach ($cells as $cell) {
            [$column, $row] = [substr($cell, 0, 1), substr($cell, 1)];

            // The label sits in column C for the leave types and column I for
            // everything on the right.
            $label = $sheet->getCell(($column === 'R' ? 'C' : 'I').$row)->getValue();

            $rows[] = [$cell, mb_substr(trim((string) $label), 0, 56)];
        }

        $book->disconnectWorksheets();

        return $rows;
    }
}
