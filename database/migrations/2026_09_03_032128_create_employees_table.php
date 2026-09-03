<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees — the HR record for each person.
 * Kept separate from `users`, which holds only login credentials.
 *
 * Placement rules:
 *   - Rank & file / Section Head  -> section_id (the division comes from the section)
 *   - Division Head               -> division_id only, no section
 *   - Chief of Hospital           -> no section or division, is_chief_of_hospital = true
 *
 * `employee_number` is the stable key shared with `ipcr-system-laravel`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Login account. Nullable because an employee record can exist before an account is issued.
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained()->nullOnDelete();

            $table->string('employee_number', 50)->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix', 20)->nullable();          // Jr., III

            // Exactly ONE plantilla position
            $table->foreignId('position_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('section_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->boolean('is_chief_of_hospital')->default(false);

            $table->date('date_hired')->nullable();
            $table->string('employment_status', 30)->default('permanent');

            // Matches an employee to their row in the biometric device export (Phase 2).
            $table->string('biometric_id', 50)->nullable()->unique();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();   // never hard-delete: records hang off this for years

            $table->index(['last_name', 'first_name']);
            $table->index('is_active');
            $table->index('is_chief_of_hospital');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
