<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 items 31, 32 and 33 — special skills and hobbies, non-academic
 * distinctions, and memberships.
 *
 * One table for all three. Each is an ordered list of single-line text, and
 * three tables of identical shape would triple the component, the validation
 * and the exporter for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_other_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 20);
            $table->string('value', 500);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // The three lists are read and written independently, so the index
            // carries the kind.
            $table->index(['employee_id', 'kind', 'sort_order'], 'pds_other_entries_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_other_entries');
    }
};
