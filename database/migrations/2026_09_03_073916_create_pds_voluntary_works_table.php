<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 item 29 — voluntary work or involvement in civic or
 * non-government organisations.
 *
 * The form prints the organisation's name and address in one cell, so it is
 * one column here. Splitting it would invent a structure the source does not
 * have, and the export would only have to join it back together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_voluntary_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('organization_name_address', 500)->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedSmallInteger('number_of_hours')->nullable();
            $table->string('position_nature_of_work')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_voluntary_works');
    }
};
