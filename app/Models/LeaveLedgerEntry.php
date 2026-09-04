<?php

namespace App\Models;

use App\Enums\LeaveLedgerKind;
use Database\Factories\LeaveLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'ledger', 'kind', 'days', 'effective_date',
    'period', 'description', 'created_by_user_id',
])]
class LeaveLedgerEntry extends Model
{
    /** @use HasFactory<LeaveLedgerEntryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => LeaveLedgerKind::class,
            'days' => 'float',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
