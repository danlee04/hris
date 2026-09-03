<?php

namespace App\Livewire\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Add and remove rows without a page reload — the reason this phase uses
 * Livewire at all.
 *
 * Each row carries a `key`, generated once and never reused. Blade binds
 * wire:key to that rather than to the array index: with an index key, deleting
 * a row in the middle makes every row below it render the one above's content.
 * That is the most common bug in Livewire repeaters and among the hardest to
 * see, because the page still looks plausible.
 */
trait ManagesRepeatingRows
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $nextKey = 0;

    /** @return array<string, mixed> */
    abstract protected function blankRow(): array;

    public function addRow(): void
    {
        $this->rows[] = array_merge($this->blankRow(), [
            'id' => null,
            'key' => 'row-'.$this->nextKey++,
        ]);
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);

        $this->rows = array_values($this->rows);
    }

    /**
     * @param  iterable<int, Model>  $records
     * @param  list<string>  $columns
     */
    protected function loadRows(iterable $records, array $columns): void
    {
        $this->rows = [];

        foreach ($records as $record) {
            $row = ['id' => $record->id, 'key' => 'row-'.$this->nextKey++];

            foreach ($columns as $column) {
                $value = $record->{$column};

                // A date cast hands back a Carbon instance; the input holds a string.
                $row[$column] = $value instanceof DateTimeInterface
                    ? $value->format('Y-m-d')
                    : $value;
            }

            $this->rows[] = $row;
        }

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    /**
     * Strips the display-only key before the rows reach RowWriter.
     *
     * @return list<array<string, mixed>>
     */
    protected function rowsForWriting(): array
    {
        return array_map(
            fn (array $row) => array_diff_key($row, ['key' => null]),
            array_values($this->rows)
        );
    }

    /**
     * Drops rows the employee never filled in. An empty repeater renders one
     * blank row so there is something to type into; that is not an entry.
     *
     * @return list<array<string, mixed>>
     */
    protected function filledRows(string $requiredColumn): array
    {
        return array_values(array_filter(
            $this->rowsForWriting(),
            fn (array $row) => trim((string) ($row[$requiredColumn] ?? '')) !== ''
        ));
    }
}
