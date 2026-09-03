<?php

namespace App\Services\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;

/**
 * The lookups the parser validates against and the importer resolves with.
 *
 * They share this class deliberately. When the two built their own lookups,
 * any difference between them meant the preview could say a row was fine and
 * the import could still write a null — a defect with no visible symptom until
 * someone noticed an employee had no position months later.
 *
 * Matching ignores case throughout. A roster typed by a person says
 * "Medical Officer III" where the plantilla says "MEDICAL OFFICER III", and
 * refusing that is a defect in the importer, not in their file.
 */
final class ReferenceData
{
    /**
     * @param  array<string, int>  $divisions  upper-cased code => id
     * @param  array<string, int>  $sections  upper-cased code => id
     * @param  array<string, int>  $positions  lower-cased title => id
     * @param  array<string, true>  $takenNumbers
     * @param  array<string, true>  $takenBiometricIds
     */
    private function __construct(
        private readonly array $divisions,
        private readonly array $sections,
        private readonly array $positions,
        private readonly array $takenNumbers,
        private readonly array $takenBiometricIds,
    ) {}

    public static function load(): self
    {
        return new self(
            self::keyBy(Division::pluck('id', 'code')->all(), mb_strtoupper(...)),
            self::keyBy(Section::pluck('id', 'code')->all(), mb_strtoupper(...)),
            self::keyBy(Position::pluck('id', 'title')->all(), mb_strtolower(...)),
            // withTrashed: the unique index does not care that a row is soft
            // deleted, so neither can this check.
            array_fill_keys(Employee::withTrashed()->pluck('employee_number')->all(), true),
            array_fill_keys(
                Employee::withTrashed()->whereNotNull('biometric_id')->pluck('biometric_id')->all(),
                true
            ),
        );
    }

    public function divisionId(string $code): ?int
    {
        return $this->divisions[mb_strtoupper(trim($code))] ?? null;
    }

    public function sectionId(string $code): ?int
    {
        return $this->sections[mb_strtoupper(trim($code))] ?? null;
    }

    public function positionId(string $title): ?int
    {
        return $this->positions[mb_strtolower(trim($title))] ?? null;
    }

    public function numberIsTaken(string $number): bool
    {
        return isset($this->takenNumbers[$number]);
    }

    /**
     * biometric_id is unique in the schema too. Leaving it unchecked here means
     * the preview reports a clean file and the import dies part-way through on
     * a duplicate key — which is exactly what happened on the first real load.
     */
    public function biometricIdIsTaken(string $biometricId): bool
    {
        return isset($this->takenBiometricIds[$biometricId]);
    }

    /**
     * @param  array<string, int>  $pairs
     * @param  callable(string): string  $normalize
     * @return array<string, int>
     */
    private static function keyBy(array $pairs, callable $normalize): array
    {
        $keyed = [];

        foreach ($pairs as $key => $id) {
            $keyed[$normalize(trim((string) $key))] = $id;
        }

        return $keyed;
    }
}
