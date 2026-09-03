<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 items 21-26.
 *
 * One-to-many, not five fixed rows. Employees hold two degrees or two master's
 * programmes and the form allows it. The legacy system inserted exactly five
 * blank rows per employee — one per level — which is why that table holds
 * hundreds of records with nothing in them.
 *
 * The years are integers. In the legacy table they were varchar, and they held
 * 'w' and 'www'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('level', 20)->nullable();
            $table->string('school_name')->nullable();
            $table->string('degree_course')->nullable();
            $table->unsignedSmallInteger('period_from')->nullable();
            $table->unsignedSmallInteger('period_to')->nullable();
            $table->string('highest_level_units', 120)->nullable();   // if not graduated
            $table->unsignedSmallInteger('year_graduated')->nullable();
            $table->string('honours')->nullable();                     // scholarships, academic honours

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_educations');
    }
};
