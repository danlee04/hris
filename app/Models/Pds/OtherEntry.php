<?php

namespace App\Models\Pds;

use App\Enums\OtherEntryKind;
use App\Models\Employee;
use Database\Factories\Pds\OtherEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'kind', 'value', 'sort_order'])]
class OtherEntry extends Model
{
    /** @use HasFactory<OtherEntryFactory> */
    use HasFactory;

    protected $table = 'pds_other_entries';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => OtherEntryKind::class];
    }

    public function scopeOfKind(Builder $query, OtherEntryKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
