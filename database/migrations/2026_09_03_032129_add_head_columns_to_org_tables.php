<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Head assignments. These live here rather than in the create migrations
 * because they point at `employees`, which points back at divisions and
 * sections — one of the two has to be added second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->foreignId('division_head_employee_id')->nullable()->after('code')
                ->constrained('employees')->nullOnDelete();
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('section_head_employee_id')->nullable()->after('code')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_head_employee_id');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_head_employee_id');
        });
    }
};
