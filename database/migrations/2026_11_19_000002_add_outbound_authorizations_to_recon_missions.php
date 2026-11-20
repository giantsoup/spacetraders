<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recon_missions', function (Blueprint $table) {
            $table->json('outbound_authorizations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recon_missions', fn (Blueprint $table) => $table->dropColumn('outbound_authorizations'));
    }
};
