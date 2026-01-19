<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mixpost_post_accounts', function (Blueprint $table) {
            $table->json('api_response')->nullable()->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('mixpost_post_accounts', function (Blueprint $table) {
            $table->dropColumn('api_response');
        });
    }
};
