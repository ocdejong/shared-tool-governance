<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('governance_invocations', 'reason')) {
            return;
        }

        $rows = GovernanceInvocation::query();
        $rows->getQuery()->select(['id', 'reason', 'frozen_inputs'])->whereNotNull('reason')->whereNotNull('frozen_inputs')->useWritePdo();
        foreach ($rows->lazyById() as $row) {
            $inputs = $row->getAttribute('frozen_inputs');
            if (isset($inputs['reason'])) {
                continue;
            }

            // Changing updated_at would restart retention for existing terminal calls.
            $row->timestamps = false;
            $row->forceFill(['frozen_inputs' => $inputs + ['reason' => $row->getAttribute('reason')]])->saveQuietly();
        }

        Schema::table('governance_invocations', static function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('governance_invocations', 'reason')) {
            Schema::table('governance_invocations', static function (Blueprint $table): void {
                $table->text('reason')->nullable();
            });
        }

        $rows = GovernanceInvocation::query();
        $rows->getQuery()->select(['id', 'frozen_inputs'])->whereNotNull('frozen_inputs')->useWritePdo();
        foreach ($rows->lazyById() as $row) {
            $reason = $row->getAttribute('frozen_inputs')['reason'] ?? null;
            if (is_string($reason)) {
                DB::table('governance_invocations')->where('id', $row->getKey())->update(['reason' => $reason]);
            }
        }
    }
};
