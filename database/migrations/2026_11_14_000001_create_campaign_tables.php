<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64)->unique();
            $table->string('name');
            $table->text('source');
            $table->json('body');
            $table->timestamps();
        });
        Schema::create('campaign_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_revision_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('revision_hash', 64);
            $table->json('limits');
            $table->unsignedTinyInteger('slot')->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->string('wait_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('last_directed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('campaign_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_grant_id')->constrained();
            $table->foreignId('agent_id')->constrained();
            $table->string('reset_id');
            $table->bigInteger('start_credits');
            $table->string('phase');
            $table->json('state')->nullable();
            $table->timestamp('next_reset_at');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_grant_id', 'reset_id']);
        });
        Schema::create('campaign_events', function (Blueprint $table) {
            $table->id();
            $table->index(['campaign_episode_id', 'kind']);
            $table->foreignId('campaign_grant_id')->constrained();
            $table->foreignId('campaign_episode_id')->nullable()->constrained();
            $table->string('kind');
            $table->json('data');
            $table->timestamps();
        });
        Schema::create('campaign_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_episode_id')->constrained();
            $table->bigInteger('credits');
            $table->string('phase');
            $table->json('ships');
            $table->json('request_budget');
            $table->timestamp('observed_at');
            $table->timestamps();
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->foreignId('campaign_episode_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('campaign_episode_id'));
        foreach (['campaign_samples', 'campaign_events', 'campaign_episodes', 'campaign_grants', 'campaign_revisions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
