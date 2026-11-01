<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_observations', function (Blueprint $table) {
            $table->string('source')->default('legacy');
            $table->string('observer_ship')->nullable();
        });
        Schema::create('observation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->string('kind');
            $table->string('symbol');
            $table->string('source');
            $table->string('observer_ship')->nullable();
            $table->json('data');
            $table->timestamp('observed_at');
            $table->index(['agent_id', 'reset_id', 'kind', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_history');
        Schema::table('game_observations', fn (Blueprint $table) => $table->dropColumn(['source', 'observer_ship']));
    }
};
