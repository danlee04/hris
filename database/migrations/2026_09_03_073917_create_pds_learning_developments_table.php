<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 item 30 — learning and development interventions.
 *
 * This is the same ground the legacy training system covers with its
 * `trainings` and `ldi_training` tables. Nothing is migrated from there; when
 * the L&D pillar is rebuilt it will read from this table, not the other way
 * round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_learning_developments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('title', 500)->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedSmallInteger('number_of_hours')->nullable();
            $table->string('type', 20)->nullable();
            $table->string('conducted_by')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_learning_developments');
    }
};
