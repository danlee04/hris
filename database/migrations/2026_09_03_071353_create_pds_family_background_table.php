<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 items 17, 19 and 20 — spouse, father, and mother's maiden name.
 * Item 18, the children, is a list and lives in its own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_family_background', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();

            // 17 — spouse
            $table->string('spouse_surname', 100)->nullable();
            $table->string('spouse_first_name', 100)->nullable();
            $table->string('spouse_middle_name', 100)->nullable();
            $table->string('spouse_name_extension', 20)->nullable();
            $table->string('spouse_occupation', 150)->nullable();
            $table->string('spouse_employer', 150)->nullable();
            $table->string('spouse_business_address', 255)->nullable();
            $table->string('spouse_telephone_no', 40)->nullable();

            // 19 — father
            $table->string('father_surname', 100)->nullable();
            $table->string('father_first_name', 100)->nullable();
            $table->string('father_middle_name', 100)->nullable();
            $table->string('father_name_extension', 20)->nullable();

            // 20 — mother, maiden name
            $table->string('mother_surname', 100)->nullable();
            $table->string('mother_first_name', 100)->nullable();
            $table->string('mother_middle_name', 100)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_family_background');
    }
};
