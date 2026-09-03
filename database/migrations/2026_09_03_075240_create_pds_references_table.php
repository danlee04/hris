<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 item 41 — three character references.
 *
 * The form prints exactly three rows. The table does not enforce that: an
 * employee typing a fourth is not a database error, and the export decides
 * what fits on the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('address', 500)->nullable();
            $table->string('telephone_no', 60)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_references');
    }
};
