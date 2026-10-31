<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('canonical_identity', 64)->nullable()->unique();
            $table->string('reset_id')->nullable();
            $table->unsignedTinyInteger('token_version')->default(0);
            $table->string('connection_state')->default('unsynchronized');
            $table->bigInteger('credits')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
        Schema::create('ships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->json('snapshot');
            $table->timestamp('observed_at');
            $table->unique(['agent_id', 'symbol']);
        });
        Schema::create('game_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('kind');
            $table->string('symbol');
            $table->json('data');
            $table->timestamp('observed_at');
            $table->unique(['agent_id', 'kind', 'symbol']);
        });
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('active_agent_id')->nullable()->unique();
            $table->string('reset_id');
            $table->string('ship_symbol');
            $table->string('state');
            $table->unsignedInteger('generation')->default(1);
            $table->json('plan');
            $table->json('totals');
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamp('next_action_at')->nullable()->index();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('blocked_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('ship_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 64);
            $table->string('reset_id');
            $table->string('ship_symbol');
            $table->string('origin');
            $table->string('command');
            $table->string('state')->index();
            $table->json('arguments');
            $table->json('before_state')->nullable();
            $table->json('outcome')->nullable();
            $table->string('message')->nullable();
            $table->unsignedInteger('api_code')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'key']);
        });
        Schema::create('game_runtime', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        foreach (['jobs', 'game_runtime', 'ship_actions', 'automation_runs', 'game_observations', 'ships'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['canonical_identity']);
            $table->dropColumn(['canonical_identity', 'reset_id', 'token_version', 'connection_state', 'credits', 'synced_at']);
        });
    }
};
