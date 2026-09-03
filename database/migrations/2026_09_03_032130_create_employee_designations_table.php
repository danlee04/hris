<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work assigned on top of the plantilla position. Unlike a position, a person
 * may hold several designations at once, and a designation has a life of its
 * own — a start date, an end date, and the Office Order that created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained()->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();               // null = still current
            $table->string('order_reference')->nullable();      // Office Order / Special Order no.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'designation_id', 'start_date'], 'emp_desig_unique');
            $table->index(['employee_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_designations');
    }
};
