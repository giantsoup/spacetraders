<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->unique(['active_agent_id', 'ship_symbol']);
            $table->dropUnique(['active_agent_id']);
        });
    }

    public function down(): void
    {
        // Refuse rollback if several ships are active; never discard live authority.
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->unique('active_agent_id');
            $table->dropUnique(['active_agent_id', 'ship_symbol']);
        });
    }
};
