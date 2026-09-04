<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signs item 7.A of CS Form 6.
 *
 * The same shape as `is_chief_of_hospital`, and for the same reason: the form
 * names a person, and that person is a fact about the hospital rather than
 * about whoever happened to press the button.
 *
 * It is deliberately not a role. The HR account can be shared, can be a stand-
 * in, can be an administrator covering a vacancy; the name printed under
 * "Human Resource Development Officer" must not change because of any of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_hr_officer')->default(false)->after('is_chief_of_hospital');

            $table->index('is_hr_officer');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['is_hr_officer']);
            $table->dropColumn('is_hr_officer');
        });
    }
};
