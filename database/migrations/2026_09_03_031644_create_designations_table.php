<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A designation is work assigned on top of the plantilla position — "OIC,
 * Budget Officer". Unlike a position, a person may hold several at once.
 *
 * `ipcr-system-laravel` reached this shape across two migrations, adding the
 * division and section columns later. This is a fresh database, so they are
 * consolidated here; the resulting columns are identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('title');                 // "OIC - Budget Officer"
            $table->text('description')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
