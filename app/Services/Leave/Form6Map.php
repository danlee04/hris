<?php

namespace App\Services\Leave;

use InvalidArgumentException;

/**
 * The cell references, read from config rather than scattered through the
 * exporter.
 *
 * A missing key throws by name. A wrong cell reference on a page of a hundred
 * cells is invisible; a null one is worse, because it writes to A1.
 */
class Form6Map
{
    public function path(): string
    {
        return config('form6_template.path');
    }

    public function sheet(): string
    {
        return config('form6_template.sheet');
    }

    public function dateFormat(): string
    {
        return config('form6_template.date_format');
    }

    public function cell(string $key): string
    {
        $cell = config("form6_template.cells.{$key}");

        if ($cell === null) {
            throw new InvalidArgumentException("No cell is mapped for [{$key}].");
        }

        return $cell;
    }

    /** @return array{cell: string, format: string} */
    public function caption(string $key): array
    {
        $caption = config("form6_template.captions.{$key}");

        if ($caption === null) {
            throw new InvalidArgumentException("No caption is mapped for [{$key}].");
        }

        return $caption;
    }

    /** An option that is not on the form ticks nothing, which is not an error. */
    public function tick(string $group, ?string $option): ?string
    {
        if ($option === null) {
            return null;
        }

        return config("form6_template.ticks.{$group}.{$option}");
    }
}
