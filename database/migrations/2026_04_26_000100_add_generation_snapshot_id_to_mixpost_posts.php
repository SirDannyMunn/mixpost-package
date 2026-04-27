<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mixpost_posts') || Schema::hasColumn('mixpost_posts', 'generation_snapshot_id')) {
            return;
        }

        Schema::table('mixpost_posts', function (Blueprint $table) {
            $table->uuid('generation_snapshot_id')->nullable()->after('published_at');
            $table->index('generation_snapshot_id', 'mixpost_posts_generation_snapshot_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mixpost_posts') || !Schema::hasColumn('mixpost_posts', 'generation_snapshot_id')) {
            return;
        }

        Schema::table('mixpost_posts', function (Blueprint $table) {
            $table->dropIndex('mixpost_posts_generation_snapshot_idx');
            $table->dropColumn('generation_snapshot_id');
        });
    }
};