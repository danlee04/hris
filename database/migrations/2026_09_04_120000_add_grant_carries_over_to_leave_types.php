<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Does an unspent yearly grant survive into the next year?
 *
 * For Special Privilege Leave it does not — three days a year, forfeited if
 * unused, under Sec. 21 of the Omnibus Rules. Solo Parent Leave is the same.
 * Wellness Leave is DTRC's own and the hospital decides, so this is a column on
 * the type rather than a rule in code.
 *
 * The default is false because forfeiting is the CSC norm. A type that should
 * carry over says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('grant_carries_over')->default(false)->after('grant_days_per_year');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('grant_carries_over');
        });
    }
};
