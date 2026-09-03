<?php

namespace App\Services\Pds;

use BackedEnum;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The single place a value becomes a cell.
 *
 * Both the page writer and the repeating-section writer go through here, so a
 * date cannot be formatted one way on page 1 and another way on a continuation
 * sheet.
 */
class CellWriter
{
    public function __construct(private readonly TemplateMap $map) {}

    /**
     * Everything reaches the sheet as text.
     *
     * A date written as a spreadsheet serial renders in whatever the reader's
     * locale decides, and this form asks for dd/mm/yyyy on every date field —
     * 04/12 is a different day in two of them. An enum written raw would print
     * `solo_parent` where the form expects "Solo Parent".
     */
    public function put(Worksheet $sheet, string $reference, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $sheet->setCellValueExplicit($reference, $this->asText($value), DataType::TYPE_STRING);
    }

    /** Ticks an Excel form control by setting the cell it is linked to. */
    public function tick(Worksheet $sheet, string $reference): void
    {
        $sheet->setCellValueExplicit($reference, true, DataType::TYPE_BOOL);
    }

    public function asText(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($this->map->dateFormat());
        }

        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        // The work experience column header asks the question outright:
        // "GOV'T SERVICE (Y/ N)". A 1 is not an answer to it.
        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }

        return (string) $value;
    }
}
