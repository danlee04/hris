<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a hold, a release and a commit back to the application that caused them.
 *
 * The ledger is the answer to "where did my credits go". Without this the
 * answer is "a hold", which is not an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->foreignId('leave_application_id')->nullable()->after('period')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['leave_application_id']);
            $table->dropColumn('leave_application_id');
        });
    }
};
