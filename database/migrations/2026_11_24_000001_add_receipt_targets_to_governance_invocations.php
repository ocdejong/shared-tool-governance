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
            $table->text('receipt_targets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('governance_invocations', function (Blueprint $table): void {
            $table->dropColumn('receipt_targets');
        });
    }
};
