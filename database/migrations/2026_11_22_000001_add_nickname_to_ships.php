<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ships', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('symbol');
        });
    }

    public function down(): void
    {
        Schema::table('ships', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
