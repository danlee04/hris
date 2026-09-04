<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One leave application, in the shape of CS Form 6.
 *
 * The paid and unpaid days are stored rather than computed on the way out. They
 * are decided against the balance at the moment of filing, and item 7.C of the
 * form prints them; recomputing later against a balance that has moved would
 * print a different form than the one that was signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            $table->date('date_from');
            $table->date('date_to');

            // Halves, because CSC leave is filed in half days.
            $table->decimal('days', 5, 2);
            $table->decimal('days_with_pay', 5, 2)->default(0);
            $table->decimal('days_without_pay', 5, 2)->default(0);

            // Item 6.B: the answers differ by type, so this is not one text box.
            $table->json('details')->nullable();

            // Item 6.D.
            $table->string('commutation', 20)->default('not_requested');

            $table->string('status', 20)->default('pending');
            $table->timestamp('filed_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};
