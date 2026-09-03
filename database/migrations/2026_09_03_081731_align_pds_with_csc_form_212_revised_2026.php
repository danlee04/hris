<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema was built against CS Form 212 Revised 2017. The form the hospital
 * actually uses is Revised 2026, and it moved three things:
 *
 *   - GSIS and SSS are gone. Item 10 is now UMID ID NO.
 *   - Item 41 asks for "CONTACT NO. AND/OR EMAIL", not a telephone number.
 *   - Solo parent is now a civil status as well as item 40.
 *
 * Done while the tables are still empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pds_personal_information', function (Blueprint $table) {
            $table->renameColumn('gsis_id', 'umid_id');
        });

        Schema::table('pds_personal_information', function (Blueprint $table) {
            $table->dropColumn('sss_no');
        });

        Schema::table('pds_references', function (Blueprint $table) {
            $table->renameColumn('telephone_no', 'contact_details');
        });
    }

    public function down(): void
    {
        Schema::table('pds_personal_information', function (Blueprint $table) {
            $table->renameColumn('umid_id', 'gsis_id');
            $table->string('sss_no', 40)->nullable()->after('philhealth_no');
        });

        Schema::table('pds_references', function (Blueprint $table) {
            $table->renameColumn('contact_details', 'telephone_no');
        });
    }
};
