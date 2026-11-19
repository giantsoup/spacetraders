<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recon_missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents');
            $table->string('reset_id');
            $table->string('ship_symbol');
            $table->string('origin_system');
            $table->string('origin_gate');
            $table->json('terms');
            $table->string('terms_hash', 64);
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamp('approved_at');
            $table->string('state');
            $table->json('hold')->nullable();
            $table->string('outcome')->nullable();
            foreach (['graph', 'visits', 'capability', 'return_reservation', 'homeward_authorizations'] as $field) {
                $table->json($field);
            }
            $table->timestamp('start_window_until');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX recon_one_open_agent ON recon_missions (agent_id) WHERE closed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('recon_missions');
    }
};
