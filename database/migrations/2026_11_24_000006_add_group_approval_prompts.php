<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        Schema::table('governance_approvals', function (Blueprint $table): void {
            $table->dropIndex(['group_delivery_id']);
            $table->dropColumn('group_delivery_id');
        });
        Schema::table('governance_outbox_messages', function (Blueprint $table): void {
            $table->dropColumn('is_group');
        });
    }
};
