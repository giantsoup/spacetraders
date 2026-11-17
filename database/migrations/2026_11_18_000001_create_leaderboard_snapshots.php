<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            // The server's resetDate identifies the wipe; the latest snapshot per wipe is its final standings.
            $table->string('reset_date', 80);
            $table->timestamp('captured_at');
            $table->timestamp('next_reset_at')->nullable();
            $table->string('source', 20)->default('api');
            $table->json('most_credits');
            $table->json('most_submitted_charts');
            $table->json('stats')->nullable();
            // Full public agent directory when collected completely; null when skipped or incomplete.
            $table->json('agents')->nullable();
            $table->unsignedInteger('agents_total')->nullable();
            $table->timestamps();
            $table->index(['reset_date', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_snapshots');
    }
};
