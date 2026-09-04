<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of every leave credit, append-only.
 *
 * There is no balance column on employees or anywhere else. A balance is
 * SUM(days). A stored balance and its entries eventually disagree, and nothing
 * in the system can say which of the two is right.
 *
 * `leave_application_id` is added in Phase 2a-2, along with the holds it
 * belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('ledger', 20);            // vacation, sick, spl, solo_parent, wellness
            $table->string('kind', 20);              // LeaveLedgerKind
            $table->decimal('days', 6, 2);           // signed: negative takes credits away
            $table->date('effective_date');

            // '2026-09' for a monthly accrual, '2026' for a yearly grant. This
            // is what makes posting idempotent, so the column is part of a
            // unique index rather than a note.
            $table->string('period', 7)->nullable();

            $table->string('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'ledger']);

            // MySQL and SQLite both treat NULLs as distinct, so entries with no
            // period — adjustments, holds, opening balances — never collide
            // with each other. Only the keyed kinds are constrained.
            $table->unique(['employee_id', 'ledger', 'kind', 'period'], 'leave_ledger_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
    }
};
