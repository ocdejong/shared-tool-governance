<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_invocations', function (Blueprint $table): void {
            $table->timestampTz('approval_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('governance_invocations', function (Blueprint $table): void {
            $table->dropColumn('approval_expires_at');
        });
    }
};
