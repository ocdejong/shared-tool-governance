<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

return new class extends Migration
{
    // Release row locks before requesting the schema lock to drop the column.
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('governance_invocations', 'reason')) {
            return;
        }

        self::forEachRetainedInvocation(static function (GovernanceInvocation $row, array $inputs): void {
            $legacyReason = $row->getRawOriginal('reason');
            if ($legacyReason === null || isset($inputs['reason'])) {
                return;
            }

            // Changing updated_at would restart retention for existing terminal calls.
            $row->timestamps = false;
            $row->forceFill(['frozen_inputs' => $inputs + ['reason' => $legacyReason]])->saveQuietly();
        });

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

        self::forEachRetainedInvocation(static function (GovernanceInvocation $row, array $inputs): void {
            $reason = $inputs['reason'] ?? null;
            if (is_string($reason)) {
                DB::table('governance_invocations')->where('id', $row->getKey())->update(['reason' => $reason]);
            }
        });
    }

    /** @param callable(GovernanceInvocation, array<string, mixed>): void $apply */
    private static function forEachRetainedInvocation(callable $apply): void
    {
        $ids = DB::table('governance_invocations')->select('id')->whereNotNull('frozen_inputs')->useWritePdo();
        $lastId = $ids->max('id');
        if ($lastId === null) {
            return;
        }

        foreach ($ids->where('id', '<=', $lastId)->lazyById() as $candidate) {
            DB::transaction(static function () use ($candidate, $apply): void {
                $query = GovernanceInvocation::query()->whereKey($candidate->id);
                $query->getQuery()->lockForUpdate();
                $row = $query->first();
                $inputs = $row?->getAttribute('frozen_inputs');
                if ($row !== null && $inputs !== null) {
                    $apply($row, $inputs);
                }
            }, 3);
        }
    }
};
