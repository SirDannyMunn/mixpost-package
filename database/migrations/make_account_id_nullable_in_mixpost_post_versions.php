<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mixpost_post_versions', function (Blueprint $table) {
            $table->uuid('account_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mixpost_post_versions', function (Blueprint $table) {
            $table->uuid('account_id')->nullable(false)->change();
        });
    }
};
