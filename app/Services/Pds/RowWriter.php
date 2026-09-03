<?php

namespace App\Services\Pds;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Synchronises one repeating PDS section: creates new rows, updates the ones
 * that came back carrying an id, orders them as given, and deletes the rest.
 *
 * The row id makes a round trip through the browser, which means it comes back
 * as whatever the person on the other end wants it to be. Every lookup here is
 * scoped to one employee_id, and a row claiming an id outside that scope is
 * refused rather than quietly skipped — a silent skip would look to the
 * employee exactly like a successful save.
 *
 * employee_id is likewise decided by the caller and overwritten on every row,
 * so a smuggled one in the payload changes nothing.
 */
class RowWriter
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $scope  extra columns that narrow which rows this call owns
     *
     * @throws AuthorizationException
     */
    public function sync(string $modelClass, int $employeeId, array $rows, array $scope = []): void
    {
        DB::transaction(function () use ($modelClass, $employeeId, $rows, $scope) {
            $owned = $modelClass::query()
                ->where('employee_id', $employeeId)
                ->where($scope)
                ->pluck('id')
                ->all();

            $keep = [];

            foreach (array_values($rows) as $position => $row) {
                $id = $row['id'] ?? null;
                unset($row['id'], $row['employee_id']);

                if ($id !== null && ! in_array((int) $id, $owned, true)) {
                    throw new AuthorizationException('That row does not belong to this employee.');
                }

                $model = $id !== null ? $modelClass::findOrFail($id) : new $modelClass;

                $model->fill(array_merge($row, $scope));
                $model->employee_id = $employeeId;
                $model->sort_order = $position;
                $model->save();

                $keep[] = $model->id;
            }

            $modelClass::query()
                ->where('employee_id', $employeeId)
                ->where($scope)
                ->whereNotIn('id', $keep ?: [0])
                ->delete();
        });
    }
}
