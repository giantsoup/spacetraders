<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->index();
            $table->string('ship')->nullable()->index();
            $table->double('value');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_metrics');
    }
};
