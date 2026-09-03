<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 items 1-16. One row per employee.
 *
 * Names live on `employees`, not here — the employee master owns them, and a
 * second copy would drift. This table starts at item 5.
 *
 * Height, weight and date of birth carry their real types. The legacy system
 * held '1' and 'sss' in its height column and a date of birth in 2026, because
 * every one of those columns was a varchar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_personal_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();

            $table->date('date_of_birth')->nullable();                 // 5
            $table->string('place_of_birth')->nullable();              // 6
            $table->string('sex', 10)->nullable();                     // 7
            $table->string('civil_status', 20)->nullable();            // 8
            $table->string('civil_status_other', 50)->nullable();
            $table->decimal('height_m', 3, 2)->nullable();             // 9  metres
            $table->decimal('weight_kg', 5, 2)->nullable();            // 10 kilograms
            $table->string('blood_type', 10)->nullable();              // 11

            $table->string('gsis_id', 40)->nullable();                 // 12
            $table->string('pagibig_id', 40)->nullable();              // 13
            $table->string('philhealth_no', 40)->nullable();           // 14
            $table->string('sss_no', 40)->nullable();                  // 15
            $table->string('tin_no', 40)->nullable();                  // 16
            $table->string('agency_employee_no', 40)->nullable();
            $table->string('philsys_id', 40)->nullable();

            $table->string('citizenship', 30)->nullable();             // Filipino | Dual Citizenship
            $table->string('dual_citizenship_by', 20)->nullable();     // by birth | by naturalization
            $table->string('dual_citizenship_country', 100)->nullable();

            // Residential address, broken out the way the form breaks it out
            $table->string('res_house_no', 60)->nullable();
            $table->string('res_street', 100)->nullable();
            $table->string('res_subdivision', 100)->nullable();
            $table->string('res_barangay', 100)->nullable();
            $table->string('res_city', 100)->nullable();
            $table->string('res_province', 100)->nullable();
            $table->string('res_zip_code', 10)->nullable();

            $table->boolean('permanent_same_as_residential')->default(false);
            $table->string('perm_house_no', 60)->nullable();
            $table->string('perm_street', 100)->nullable();
            $table->string('perm_subdivision', 100)->nullable();
            $table->string('perm_barangay', 100)->nullable();
            $table->string('perm_city', 100)->nullable();
            $table->string('perm_province', 100)->nullable();
            $table->string('perm_zip_code', 10)->nullable();

            $table->string('telephone_no', 40)->nullable();
            $table->string('mobile_no', 40)->nullable();
            $table->string('email_address')->nullable();

            $table->string('photo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_personal_information');
    }
};
