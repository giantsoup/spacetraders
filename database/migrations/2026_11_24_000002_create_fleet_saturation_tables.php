<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ship_state_chains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('ship_symbol');
            $table->json('fields');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['agent_id', 'reset_id', 'ship_symbol']);
        });

        Schema::create('observation_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('kind', 32);
            $table->string('symbol');
            $table->unsignedBigInteger('generation')->default(0);
            $table->unsignedBigInteger('last_action_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['agent_id', 'reset_id', 'kind', 'symbol']);
        });

        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->unsignedBigInteger('ship_action_id')->nullable()->unique();
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->string('ship_symbol')->nullable();
            $table->string('owner_key');
            $table->string('purpose', 64);
            $table->string('command', 64);
            $table->bigInteger('exposure');
            $table->string('state', 32)->default('reserved');
            $table->bigInteger('settled_delta')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'reset_id', 'state']);
            $table->index(['agent_id', 'reset_id', 'owner_key', 'purpose']);
        });

        Schema::create('corridor_book_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('market');
            $table->string('good');
            $table->string('side', 8);
            $table->unsignedInteger('units');
            $table->bigInteger('landing_at');
            $table->string('tie_key');
            $table->string('owner_type', 32);
            $table->string('owner_id', 64);
            $table->unsignedSmallInteger('leg_index')->default(0);
            $table->unsignedSmallInteger('batch_index')->default(0);
            $table->unsignedBigInteger('ship_action_id')->nullable();
            $table->string('state', 32)->default('reserved');
            $table->unsignedBigInteger('observed_version')->nullable();
            $table->boolean('absorbed')->default(false);
            $table->unsignedInteger('guard_price')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'reset_id', 'market', 'good', 'side', 'state'], 'corridor_book_lookup_idx');
            $table->index(['agent_id', 'reset_id', 'owner_type', 'owner_id'], 'corridor_book_owner_idx');
        });

        Schema::create('extraction_site_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('waypoint');
            $table->json('modifiers');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('observed_at');
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('reduced_rate_until')->nullable();
            $table->boolean('stripped')->default(false);
            $table->string('source', 64)->default('waypoint');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['agent_id', 'reset_id', 'waypoint']);
        });

        Schema::create('extraction_site_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('waypoint');
            $table->string('ship_symbol');
            $table->unsignedBigInteger('ship_action_id')->nullable()->unique();
            $table->bigInteger('sent_at');
            $table->string('state', 32)->default('reserved');
            $table->timestamps();
            $table->index(['agent_id', 'reset_id', 'waypoint', 'sent_at'], 'extraction_site_sends_window_idx');
        });

        Schema::create('extraction_calibrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('kind', 32);
            $table->string('waypoint');
            $table->string('config_hash', 64);
            $table->unsignedInteger('receipt_count')->default(0);
            $table->json('samples');
            $table->json('summary');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['agent_id', 'reset_id', 'kind', 'waypoint', 'config_hash'], 'extraction_calibrations_unique');
        });

        Schema::create('transfer_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->unsignedBigInteger('campaign_grant_id')->nullable();
            $table->unsignedBigInteger('campaign_episode_id')->nullable();
            $table->string('cluster_id');
            $table->string('canary_pair_id')->nullable();
            $table->string('source_ship');
            $table->string('receiver_ship');
            $table->unsignedBigInteger('source_run_id');
            $table->unsignedBigInteger('receiver_run_id');
            $table->string('waypoint');
            $table->string('good');
            $table->unsignedInteger('units');
            $table->bigInteger('cost_basis')->default(0);
            $table->unsignedBigInteger('ship_action_id')->nullable()->unique();
            $table->string('state', 32)->default('granted');
            $table->string('active_source_key')->nullable()->unique();
            $table->string('active_receiver_key')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['agent_id', 'reset_id', 'state']);
            $table->index(['agent_id', 'reset_id', 'canary_pair_id']);
        });

        Schema::create('stimulus_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->unsignedBigInteger('campaign_episode_id')->nullable();
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->string('market');
            $table->string('input_good');
            $table->bigInteger('downside_reserved')->default(0);
            $table->bigInteger('realized_loss')->default(0);
            $table->bigInteger('bonus_reserved')->default(0);
            $table->string('state', 32)->default('reserved');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->json('baseline_snapshot')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'reset_id', 'market', 'input_good', 'state'], 'stimulus_market_input_idx');
            $table->index(['agent_id', 'reset_id', 'campaign_episode_id', 'state'], 'stimulus_episode_state_idx');
        });

        Schema::create('corridor_canary_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('corridor_family', 64);
            $table->string('state', 32)->default('waiting_evidence');
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->string('canary_pair_id')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['agent_id', 'reset_id', 'corridor_family'], 'corridor_canary_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'corridor_canary_states',
            'stimulus_entries',
            'transfer_leases',
            'extraction_calibrations',
            'extraction_site_sends',
            'extraction_site_states',
            'corridor_book_events',
            'credit_reservations',
            'observation_generations',
            'ship_state_chains',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
