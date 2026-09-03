<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\ReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'name', 'address', 'contact_details', 'sort_order'])]
class Reference extends Model
{
    /** @use HasFactory<ReferenceFactory> */
    use HasFactory;

    protected $table = 'pds_references';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
