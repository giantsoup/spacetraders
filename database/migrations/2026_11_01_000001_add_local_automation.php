<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_engines', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('state')->default('off');
            $table->uuid('session_id')->nullable();
            $table->boolean('gate_open')->default(false);
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->json('processes')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();
        });
        Schema::create('opportunity_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('strategy');
            $table->string('system_symbol');
            $table->string('status');
            $table->json('coverage');
            $table->json('observations');
            $table->json('candidates');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('automation_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investigation_id')->constrained('opportunity_investigations')->cascadeOnDelete();
            $table->string('reset_id');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('plan_version')->default(2);
            $table->json('candidate');
            $table->json('limits');
            $table->timestamps();
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->string('strategy')->default('mining');
            $table->unsignedInteger('plan_version')->default(1);
            $table->uuid('engine_session_id')->nullable();
            $table->foreignId('investigation_id')->nullable()->constrained('opportunity_investigations')->nullOnDelete();
            $table->foreignId('draft_id')->nullable()->constrained('automation_drafts')->nullOnDelete();
            $table->unsignedInteger('draft_revision')->nullable();
            $table->string('fingerprint', 64)->nullable();
        });
        Schema::table('ship_actions', function (Blueprint $table) {
            $table->uuid('engine_session_id')->nullable();
            $table->string('target_type')->default('ship');
            $table->string('target_id')->nullable();
        });
        Schema::create('agent_credit_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->bigInteger('credits');
            $table->string('source')->default('first_observed_local');
            $table->timestamp('observed_at');
            $table->unique(['agent_id', 'reset_id']);
        });
        Schema::create('confirmed_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->foreignId('ship_action_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->bigInteger('credits');
            $table->timestamp('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmed_credit_entries');
        Schema::dropIfExists('agent_credit_baselines');
        Schema::table('ship_actions', fn (Blueprint $table) => $table->dropColumn(['engine_session_id', 'target_type', 'target_id']));
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_id');
            $table->dropConstrainedForeignId('investigation_id');
            $table->dropColumn(['strategy', 'plan_version', 'engine_session_id', 'draft_revision', 'fingerprint']);
        });
        Schema::dropIfExists('automation_drafts');
        Schema::dropIfExists('opportunity_investigations');
        Schema::dropIfExists('local_engines');
    }
};
