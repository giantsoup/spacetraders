<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_grants', function (Blueprint $table) {
            $table->string('agent_symbol', 32)->nullable()->after('user_id');
        });

        // Raw DB backfill every existing row (including MACSOUP and arm grants) before changing indexes.
        $revisions = DB::table('campaign_revisions')->pluck('body', 'id');
        foreach (DB::table('campaign_grants')->get(['id', 'campaign_revision_id']) as $grant) {
            $body = json_decode((string) ($revisions[$grant->campaign_revision_id] ?? '{}'), true);
            $symbol = is_array($body) ? ($body['agent']['symbol'] ?? null) : null;
            if (! is_string($symbol) || $symbol === '') {
                $agentId = DB::table('campaign_episodes')->where('campaign_grant_id', $grant->id)->value('agent_id');
                $symbol = $agentId ? DB::table('agents')->where('id', $agentId)->value('name') : null;
            }
            if (! is_string($symbol) || $symbol === '') {
                throw new RuntimeException('Cannot resolve agent symbol for campaign grant '.$grant->id);
            }
            DB::table('campaign_grants')->where('id', $grant->id)->update(['agent_symbol' => strtoupper($symbol)]);
        }

        Schema::table('campaign_grants', fn (Blueprint $table) => $table->string('agent_symbol', 32)->nullable(false)->change());

        // Drop the old global single-column slot unique index, then create the composite per-agent unique index.
        Schema::table('campaign_grants', function (Blueprint $table) {
            $table->dropUnique('campaign_grants_slot_unique');
            $table->unique(['user_id', 'agent_symbol', 'slot'], 'campaign_grants_user_agent_slot_unique');
            $table->index(['agent_symbol', 'slot'], 'campaign_grants_agent_slot_index');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_grants', function (Blueprint $table) {
            // A multi-agent database cannot satisfy the old unique slot constraint.
            // Attempt it first so a refused rollback preserves the per-agent index.
            $table->unique('slot', 'campaign_grants_slot_unique');
            $table->dropIndex('campaign_grants_agent_slot_index');
            $table->dropUnique('campaign_grants_user_agent_slot_unique');
            $table->dropColumn('agent_symbol');
        });
    }
};
