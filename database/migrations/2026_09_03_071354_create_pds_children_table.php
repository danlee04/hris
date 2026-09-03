<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 item 18.
 *
 * `sort_order` is on every repeating PDS table. Rows must print in the order
 * the employee arranged them, not in the order they happened to be inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->date('date_of_birth')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_children');
    }
};
