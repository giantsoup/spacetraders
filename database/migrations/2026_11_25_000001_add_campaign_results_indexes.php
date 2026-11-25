<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_actions', function (Blueprint $table): void {
            $table->index(['automation_run_id', 'command', 'state'], 'ship_actions_run_command_state_index');
        });
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->index(['campaign_episode_id', 'strategy'], 'automation_runs_episode_strategy_index');
        });
        // Without statistics SQLite keeps choosing the low-selectivity state index.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('ANALYZE ship_actions');
            DB::statement('ANALYZE automation_runs');
        }
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropIndex('automation_runs_episode_strategy_index');
        });
        Schema::table('ship_actions', function (Blueprint $table): void {
            $table->dropIndex('ship_actions_run_command_state_index');
        });
    }
};
