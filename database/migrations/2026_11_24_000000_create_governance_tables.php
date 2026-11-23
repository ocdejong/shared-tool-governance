<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_resource_bindings', function (Blueprint $table): void {
            $table->id();
            $table->string('host_scope', 191);
            $table->string('household_id', 191);
            $table->string('resource_type', 64);
            $table->string('resource_id', 191);
            $table->string('owner_type', 16);
            $table->string('owner_id', 191);
            $table->timestampsTz();

            $table->unique(['host_scope', 'household_id', 'resource_type', 'resource_id'], 'governance_resource_scope_unique');
        });

        Schema::create('governance_rule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_binding_id')->constrained('governance_resource_bindings');
            $table->unsignedBigInteger('revision');
            $table->boolean('enabled');
            $table->json('rules');
            $table->string('edited_by');
            $table->timestampsTz();

            $table->unique(['resource_binding_id', 'revision'], 'governance_binding_revision_unique');
        });

        Schema::create('governance_invocations', function (Blueprint $table): void {
            $table->id();
            $table->string('host_scope', 191);
            $table->string('household_id', 191);
            $table->string('invocation_id', 191);
            $table->string('tool_name');
            $table->string('tool_version');
            $table->string('action_key');
            $table->string('requester_id');
            $table->string('runtime_id');
            $table->string('executor_id');
            $table->text('reason');
            $table->json('audience');
            $table->text('frozen_inputs')->nullable();
            $table->char('fingerprint', 64);
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestampTz('outcome_recorded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['host_scope', 'household_id', 'invocation_id'], 'governance_invocation_scope_unique');
        });

        Schema::create('governance_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invocation_row_id')->constrained('governance_invocations');
            $table->ulid('approval_id')->unique();
            $table->string('owner_type', 16);
            $table->string('owner_id', 191);
            $table->string('approver_id');
            $table->text('preview')->nullable();
            $table->string('decision')->nullable();
            $table->string('reply_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['invocation_row_id', 'owner_type', 'owner_id'], 'governance_invocation_owner_unique');
        });

        Schema::create('governance_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invocation_row_id')->unique()->constrained('governance_invocations');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('redeemed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('governance_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invocation_row_id')->index()->constrained('governance_invocations');
            $table->ulid('delivery_id')->unique();
            $table->string('recipient_id');
            $table->text('payload')->nullable();
            $table->string('card_id')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('governance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('host_scope', 191);
            $table->string('household_id', 191);
            $table->foreignId('invocation_row_id')->nullable()->index()->constrained('governance_invocations');
            $table->string('event');
            $table->json('details');
            $table->timestampsTz();

            $table->index(['host_scope', 'household_id', 'created_at'], 'governance_audit_scope_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_audit_events');
        Schema::dropIfExists('governance_outbox_messages');
        Schema::dropIfExists('governance_grants');
        Schema::dropIfExists('governance_approvals');
        Schema::dropIfExists('governance_invocations');
        Schema::dropIfExists('governance_rule_revisions');
        Schema::dropIfExists('governance_resource_bindings');
    }
};
