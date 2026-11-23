<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->index(['campaign_grant_id', 'id'], 'campaign_events_grant_id_index');
        });
        Schema::table('campaign_samples', function (Blueprint $table): void {
            $table->index(['campaign_episode_id', 'observed_at'], 'campaign_samples_episode_observed_index');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_samples', function (Blueprint $table): void {
            $table->dropIndex('campaign_samples_episode_observed_index');
        });
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->dropIndex('campaign_events_grant_id_index');
        });
    }
};
