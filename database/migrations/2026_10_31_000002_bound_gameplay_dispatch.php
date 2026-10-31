<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ship_actions', 'automation_runs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('last_dispatched_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['ship_actions', 'automation_runs'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('last_dispatched_at'));
        }
    }
};
