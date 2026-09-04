<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The letters after a name on a signature line: MD, RN, RSW, MM-PA, DPCAM.
 *
 * Not `suffix`, which is the CSC "name extension" — Jr., Sr., III — and has a
 * box of its own on both CS Form 212 and CS Form 6. A doctorate is not a name
 * extension, and putting the two in one column would print "MD" where the form
 * asks for "Jr.".
 *
 * Free text, on purpose. It is whatever the person puts on their own
 * letterhead, and a list of accepted degrees maintained here would be wrong
 * within a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('credentials', 120)->nullable()->after('suffix');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('credentials');
        });
    }
};
