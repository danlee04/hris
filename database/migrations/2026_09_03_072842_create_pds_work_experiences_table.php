<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 item 28 — work experience.
 *
 * `date_to` is nullable and means the person still holds the position. The CSC
 * form prints the word "PRESENT" there; storing that string in a date column
 * is how the legacy tables ended up unqueryable.
 *
 * This is the section that overflows the printed form most often, so it is the
 * first real case for the continuation sheet in Phase 1c.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();                    // null = still in the post
            $table->string('position_title')->nullable();
            $table->string('department_agency')->nullable();
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->string('salary_grade_step', 20)->nullable();    // "15-2"
            $table->string('status_of_appointment', 60)->nullable();
            $table->boolean('is_government_service')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_work_experiences');
    }
};
