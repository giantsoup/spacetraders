<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_metrics', function (Blueprint $table) {
            $table->string('agent_symbol', 32)->nullable()->after('ship');
            $table->string('scope', 16)->default('agent')->after('agent_symbol');
            $table->index(['kind', 'agent_symbol', 'created_at']);
            $table->index(['scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('fleet_metrics', function (Blueprint $table) {
            $table->dropIndex(['kind', 'agent_symbol', 'created_at']);
            $table->dropIndex(['scope', 'created_at']);
            $table->dropColumn(['agent_symbol', 'scope']);
        });
    }
};
