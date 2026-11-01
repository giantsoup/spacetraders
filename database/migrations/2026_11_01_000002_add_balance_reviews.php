<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_credit_baselines', function (Blueprint $table) {
            $table->bigInteger('user_starting_credits')->nullable();
            $table->timestamp('user_starting_at')->nullable();
        });
        Schema::create('agent_balance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->bigInteger('adjustment');
            $table->bigInteger('observed_balance');
            $table->string('note', 500);
            $table->timestamp('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_balance_reviews');
        Schema::table('agent_credit_baselines', fn (Blueprint $table) => $table->dropColumn(['user_starting_credits', 'user_starting_at']));
    }
};
