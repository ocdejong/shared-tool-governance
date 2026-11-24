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
        Schema::table('governance_outbox_messages', function (Blueprint $table): void {
            $table->boolean('is_group')->default(false);
        });
        Schema::table('governance_approvals', function (Blueprint $table): void {
            $table->ulid('group_delivery_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        ApprovalRollback::withoutGroupPrompts(DB::connection());

        Schema::table('governance_approvals', function (Blueprint $table): void {
            $table->dropIndex(['group_delivery_id']);
            $table->dropColumn('group_delivery_id');
        });
        Schema::table('governance_outbox_messages', function (Blueprint $table): void {
            $table->dropColumn('is_group');
        });
    }
};
