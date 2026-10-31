<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_actions', fn (Blueprint $table) => $table->boolean('review_required')->default(false));
    }

    public function down(): void
    {
        Schema::table('ship_actions', fn (Blueprint $table) => $table->dropColumn('review_required'));
    }
};
