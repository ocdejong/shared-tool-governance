<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ocdejong\SharedToolGovernance\Database\ApprovalRollback;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_approvals', function (Blueprint $table): void {
            $table->string('approval_mode', 8)->nullable();
            $table->unique(['invocation_row_id', 'owner_type', 'owner_id', 'approver_id'], 'governance_requirement_person_unique');
            $table->dropUnique('governance_invocation_owner_unique');
        });
    }

    public function down(): void
    {
        ApprovalRollback::withoutEligibleCards(DB::connection());

        Schema::table('governance_approvals', function (Blueprint $table): void {
            $table->unique(['invocation_row_id', 'owner_type', 'owner_id'], 'governance_invocation_owner_unique');
            $table->dropUnique('governance_requirement_person_unique');
            $table->dropColumn('approval_mode');
        });
    }
};
