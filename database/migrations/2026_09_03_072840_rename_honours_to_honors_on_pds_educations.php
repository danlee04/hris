<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CSC form says "Scholarship/Academic Honors Received". The domain
 * vocabulary here is the form's, so the column follows it. Renamed rather than
 * left inconsistent, while the table is still empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pds_educations', function (Blueprint $table) {
            $table->renameColumn('honours', 'honors');
        });
    }

    public function down(): void
    {
        Schema::table('pds_educations', function (Blueprint $table) {
            $table->renameColumn('honors', 'honours');
        });
    }
};
