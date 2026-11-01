<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adviser_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->unsignedInteger('token_version');
            $table->string('credential_hash', 64);
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('ship_symbol');
            $table->string('system_symbol');
            $table->json('terms');
            $table->string('grant_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'version']);
        });
        Schema::create('adviser_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adviser_policy_id')->constrained()->cascadeOnDelete();
            $table->string('reset_id');
            $table->unsignedInteger('selector_version');
            $table->json('evidence');
            $table->string('evidence_hash', 64);
            $table->json('candidates');
            $table->json('selection');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['agent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adviser_decisions');
        Schema::dropIfExists('adviser_policies');
    }
};
