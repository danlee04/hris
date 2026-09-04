<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leave vocabulary, as a table rather than an enum.
 *
 * Every type carries its own rules and not just a number of days: how much
 * notice it needs, how many consecutive days it allows, which employment
 * statuses may file it. Wellness Leave is the reason — it exists at DTRC and
 * in no CSC issuance, and a rule set the hospital invents cannot live in code
 * that only a developer can change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // "VL", "WELLNESS"
            $table->string('name');
            $table->string('legal_basis')->nullable();     // printed on the form

            // Which balance it draws on. Null means the type is applied for and
            // approved but spends nothing: maternity, study, adoption.
            $table->string('ledger', 20)->nullable();

            $table->decimal('accrual_days_per_month', 5, 2)->nullable();
            $table->unsignedSmallInteger('grant_days_per_year')->nullable();
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();

            // The employment statuses that may file it.
            $table->json('applies_to');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
