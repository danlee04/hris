<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 item 27 — civil service eligibility.
 *
 * The legacy system kept a single free-text `eligibility` column on the
 * employee row, which is why one person could not hold both a PRC licence and
 * a CSC professional eligibility. Here they hold as many as they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('eligibility')->nullable();
            $table->string('rating', 20)->nullable();              // free text: "85.5", "N/A"
            $table->date('examination_date')->nullable();
            $table->string('examination_place')->nullable();
            $table->string('license_number', 60)->nullable();
            $table->date('license_validity')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_eligibilities');
    }
};
