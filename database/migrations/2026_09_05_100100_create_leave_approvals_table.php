<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signatures, written when the application is filed.
 *
 * `approver_employee_id` names the person who holds that step at that moment.
 * If the section head changes next week, the person who signed last week is
 * still the person recorded as having signed. It is null for the HR step and
 * only for the HR step: that one is held by an office, and whoever acts is
 * recorded in `acted_by_user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('sequence');
            $table->string('step', 20);

            $table->foreignId('approver_employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->string('action', 20)->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();

            $table->timestamps();

            $table->unique(['leave_application_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approvals');
    }
};
