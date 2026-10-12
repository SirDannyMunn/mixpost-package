<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('mixpost_post_versions', 'options')) {
            Schema::table('mixpost_post_versions', fn (Blueprint $table) => $table->json('options')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('mixpost_post_versions', fn (Blueprint $table) => $table->dropColumn('options'));
    }
};
