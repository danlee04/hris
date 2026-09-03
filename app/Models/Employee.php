<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Models\Pds\Child;
use App\Models\Pds\FamilyBackground;
use App\Models\Pds\PersonalInformation;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The HR record for a person. Separate from `users`, which holds only login
 * credentials — an employee exists long before anyone issues them an account,
 * and keeps existing after the account is gone.
 */
#[Fillable([
    'user_id',
    'employee_number',
    'first_name',
    'middle_name',
    'last_name',
    'suffix',
    'position_id',
    'section_id',
    'division_id',
    'is_chief_of_hospital',
    'date_hired',
    'employment_status',
    'biometric_id',
    'is_active',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * This is what replaces the approval gate the design deliberately left out:
     * an employee edits their own record with nobody signing off, and the log
     * is what makes that safe.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            // Without this every save records all fourteen columns and the log
            // becomes unreadable inside a month.
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_chief_of_hospital' => 'boolean',
            'is_active' => 'boolean',
            'date_hired' => 'date',
            'employment_status' => EmploymentStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Exactly ONE plantilla position. */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** Every designation, including ones that have ended. */
    public function designations(): BelongsToMany
    {
        return $this->belongsToMany(Designation::class, 'employee_designations')
            ->withPivot(['start_date', 'end_date', 'order_reference', 'is_active'])
            ->withTimestamps();
    }

    /** CS Form 212 items 1-16. */
    public function personalInformation(): HasOne
    {
        return $this->hasOne(PersonalInformation::class);
    }

    /** CS Form 212 items 17, 19 and 20. */
    public function familyBackground(): HasOne
    {
        return $this->hasOne(FamilyBackground::class);
    }

    /** CS Form 212 item 18, in the order the employee arranged them. */
    public function children(): HasMany
    {
        return $this->hasMany(Child::class)->orderBy('sort_order');
    }

    /** Surname first, the way HR reads a list. */
    public function fullName(): string
    {
        $name = "{$this->last_name}, {$this->first_name}";

        if ($this->middle_name) {
            $name .= ' '.mb_substr($this->middle_name, 0, 1).'.';
        }

        return $this->suffix ? "{$name} {$this->suffix}" : $name;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
