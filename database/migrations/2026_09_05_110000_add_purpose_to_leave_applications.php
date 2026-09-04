<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the leave is being asked for, in the applicant's own words.
 *
 * CS Form 6 has no box for it — item 6.B asks a fixed question of each type
 * and nothing else. This is for the people deciding: a section head reading a
 * queue of dates cannot recommend anything without knowing what the days are
 * for, and today they ask in the corridor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->text('purpose')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
