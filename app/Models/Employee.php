<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Models\Pds\Child;
use App\Models\Pds\Declaration;
use App\Models\Pds\Education;
use App\Models\Pds\Eligibility;
use App\Models\Pds\FamilyBackground;
use App\Models\Pds\LearningDevelopment;
use App\Models\Pds\OtherEntry;
use App\Models\Pds\PersonalInformation;
use App\Models\Pds\Reference;
use App\Models\Pds\VoluntaryWork;
use App\Models\Pds\WorkExperience;
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
    'credentials',
    'position_id',
    'section_id',
    'division_id',
    'is_chief_of_hospital',
    'is_hr_officer',
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
            'is_hr_officer' => 'boolean',
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

    /** CS Form 212 items 21-26. */
    public function educations(): HasMany
    {
        return $this->hasMany(Education::class)->orderBy('sort_order');
    }

    /** CS Form 212 item 27. */
    public function eligibilities(): HasMany
    {
        return $this->hasMany(Eligibility::class)->orderBy('sort_order');
    }

    /** CS Form 212 item 28. */
    public function workExperiences(): HasMany
    {
        return $this->hasMany(WorkExperience::class)->orderBy('sort_order');
    }

    /** CS Form 212 item 29. */
    public function voluntaryWorks(): HasMany
    {
        return $this->hasMany(VoluntaryWork::class)->orderBy('sort_order');
    }

    /** CS Form 212 item 30. */
    public function learningDevelopments(): HasMany
    {
        return $this->hasMany(LearningDevelopment::class)->orderBy('sort_order');
    }

    /** CS Form 212 items 31-33, all three lists in one relation. */
    public function otherEntries(): HasMany
    {
        return $this->hasMany(OtherEntry::class)->orderBy('kind')->orderBy('sort_order');
    }

    /** CS Form 212 items 34-40 and 42. */
    public function declaration(): HasOne
    {
        return $this->hasOne(Declaration::class);
    }

    /** CS Form 212 item 41. */
    public function references(): HasMany
    {
        return $this->hasMany(Reference::class)->orderBy('sort_order');
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

    /**
     * The way a name is printed above a signature line: given name first, in
     * capitals, with the letters after it.
     *
     * MARY JANE E. LAO GUICO
     * EDHEL S. MIRO, MD, DPCAM, MM-PA
     *
     * Not fullName(), which is surname-first for reading a list of 134 people.
     * The two are different questions and a form that used the list order would
     * read as a filing entry rather than as somebody's signature.
     *
     * The name is capitalised; the credentials are left as the person writes
     * them, because PhD and RN and MM-PA do not agree on capitals.
     */
    public function signatureName(): string
    {
        $name = $this->first_name;

        if ($this->middle_name) {
            $name .= ' '.mb_substr($this->middle_name, 0, 1).'.';
        }

        $name .= " {$this->last_name}";

        if ($this->suffix) {
            $name .= " {$this->suffix}";
        }

        $name = mb_strtoupper(trim($name));

        return $this->credentials ? "{$name}, {$this->credentials}" : $name;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
