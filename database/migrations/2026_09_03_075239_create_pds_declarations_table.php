<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS Form 212 items 34-40 and 42. One row per employee.
 *
 * This is the most sensitive table in the system. Items 35 and 36 record
 * administrative and criminal cases, item 40 records disability and solo
 * parent status. Reading it is logged; the export is the only other way out.
 *
 * Every question is a boolean paired with a details column. The boolean stays
 * nullable rather than defaulting to false: unanswered and "no" are different
 * things on a form signed under penalty of perjury, and the completeness
 * check needs to tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();

            // 34 — related to the appointing or recommending authority
            $table->boolean('q34_related_third_degree')->nullable();
            $table->boolean('q34_related_fourth_degree')->nullable();
            $table->string('q34_related_details', 500)->nullable();

            // 35 — administrative offense and criminal charge
            $table->boolean('q35_administrative_offense')->nullable();
            $table->string('q35_administrative_details', 500)->nullable();
            $table->boolean('q35_criminally_charged')->nullable();
            $table->string('q35_criminal_details', 500)->nullable();
            $table->date('q35_date_filed')->nullable();
            $table->string('q35_case_status', 255)->nullable();

            // 36 — convicted of a crime or violation of law
            $table->boolean('q36_convicted')->nullable();
            $table->string('q36_details', 500)->nullable();

            // 37 — separated from the service
            $table->boolean('q37_separated_from_service')->nullable();
            $table->string('q37_details', 500)->nullable();

            // 38 — election candidacy and resignation to campaign
            $table->boolean('q38_candidate_in_election')->nullable();
            $table->string('q38_candidate_details', 500)->nullable();
            $table->boolean('q38_resigned_to_campaign')->nullable();
            $table->string('q38_resigned_details', 500)->nullable();

            // 39 — immigrant or permanent resident of another country
            $table->boolean('q39_immigrant_or_permanent_resident')->nullable();
            $table->string('q39_details', 500)->nullable();

            // 40 — indigenous group, disability, solo parent
            $table->boolean('q40_indigenous_group')->nullable();
            $table->string('q40_indigenous_details', 255)->nullable();
            $table->boolean('q40_person_with_disability')->nullable();
            $table->string('q40_pwd_id_no', 60)->nullable();
            $table->boolean('q40_solo_parent')->nullable();
            $table->string('q40_solo_parent_id_no', 60)->nullable();

            // 42 — government issued ID
            $table->string('government_id_type', 120)->nullable();
            $table->string('government_id_number', 60)->nullable();
            $table->string('government_id_issued', 255)->nullable();  // date and place, as the form prints it

            $table->date('date_accomplished')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_declarations');
    }
};
