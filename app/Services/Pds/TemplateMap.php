<?php

namespace App\Services\Pds;

use InvalidArgumentException;

/**
 * Reads `config/pds_template.php` and refuses to answer a question it does not
 * have an answer to.
 *
 * The loudness is the point. On a form of 150 fields, a mistyped dot path that
 * quietly returned null would write nothing into one cell, and nobody would
 * notice until an employee's PDS came back from the CSC with a blank where
 * their TIN should be.
 */
class TemplateMap
{
    public function path(): string
    {
        return $this->get('path');
    }

    public function dateFormat(): string
    {
        return $this->get('date_format');
    }

    /** The real worksheet name behind one of our keys. */
    public function sheet(string $key): string
    {
        return $this->get("sheets.{$key}");
    }

    /** One A1 reference, by dot path — for example `personal_information.cells.surname`. */
    public function cell(string $path): string
    {
        return $this->get($path);
    }

    /**
     * A whole section's configuration.
     *
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $section = $this->get($key);

        if (! is_array($section)) {
            throw new InvalidArgumentException("[{$key}] is not a section in the PDS template map.");
        }

        return $section;
    }

    /** Every cell of a one-to-one section, keyed by field name. */
    public function cells(string $key): array
    {
        return $this->get("{$key}.cells");
    }

    private function get(string $path): mixed
    {
        $value = config("pds_template.{$path}");

        if ($value === null) {
            throw new InvalidArgumentException(
                "[{$path}] is not in the PDS template map. See config/pds_template.php."
            );
        }

        return $value;
    }
}
