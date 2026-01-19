<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add organization_id to all Mixpost tables for multi-tenant support.
 * 
 * This migration adds a UUID organization_id column to enable filtering
 * data by organization. The column is nullable for backwards compatibility
 * with existing data.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add organization_id to accounts
        if (!Schema::hasColumn('mixpost_accounts', 'organization_id')) {
            Schema::table('mixpost_accounts', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                $table->uuid('connected_by')->nullable()->after('organization_id');
                $table->timestamp('connected_at')->nullable()->after('connected_by');
                
                $table->index('organization_id', 'mixpost_accounts_org_idx');
            });
        }

        // Add organization_id to posts
        if (!Schema::hasColumn('mixpost_posts', 'organization_id')) {
            Schema::table('mixpost_posts', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                $table->uuid('created_by')->nullable()->after('organization_id');
                
                $table->index('organization_id', 'mixpost_posts_org_idx');
            });
        }

        // Add organization_id to tags
        if (!Schema::hasColumn('mixpost_tags', 'organization_id')) {
            Schema::table('mixpost_tags', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                
                $table->index('organization_id', 'mixpost_tags_org_idx');
            });
        }

        // Add organization_id to media
        if (!Schema::hasColumn('mixpost_media', 'organization_id')) {
            Schema::table('mixpost_media', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                $table->uuid('uploaded_by')->nullable()->after('organization_id');
                
                $table->index('organization_id', 'mixpost_media_org_idx');
            });
        }

        // Add organization_id to settings
        if (!Schema::hasColumn('mixpost_settings', 'organization_id')) {
            Schema::table('mixpost_settings', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                
                $table->index('organization_id', 'mixpost_settings_org_idx');
                
                // Update unique constraint to include organization
                $table->unique(['organization_id', 'name'], 'mixpost_settings_org_name_unq');
            });
        }

        // Add organization_id to imported_posts
        if (!Schema::hasColumn('mixpost_imported_posts', 'organization_id')) {
            Schema::table('mixpost_imported_posts', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                
                $table->index('organization_id', 'mixpost_imported_posts_org_idx');
            });
        }

        // Add organization_id to facebook_insights
        if (!Schema::hasColumn('mixpost_facebook_insights', 'organization_id')) {
            Schema::table('mixpost_facebook_insights', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                
                $table->index('organization_id', 'mixpost_fb_insights_org_idx');
            });
        }

        // Add organization_id to metrics
        if (!Schema::hasColumn('mixpost_metrics', 'organization_id')) {
            Schema::table('mixpost_metrics', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                
                $table->index('organization_id', 'mixpost_metrics_org_idx');
            });
        }

        // Add organization_id to audience
        if (!Schema::hasColumn('mixpost_audience', 'organization_id')) {
            Schema::table('mixpost_audience', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                
                $table->index('organization_id', 'mixpost_audience_org_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mixpost_accounts', function (Blueprint $table) {
            $table->dropIndex('mixpost_accounts_org_idx');
            $table->dropColumn(['organization_id', 'connected_by', 'connected_at']);
        });

        Schema::table('mixpost_posts', function (Blueprint $table) {
            $table->dropIndex('mixpost_posts_org_idx');
            $table->dropColumn(['organization_id', 'created_by']);
        });

        Schema::table('mixpost_tags', function (Blueprint $table) {
            $table->dropIndex('mixpost_tags_org_idx');
            $table->dropColumn('organization_id');
        });

        Schema::table('mixpost_media', function (Blueprint $table) {
            $table->dropIndex('mixpost_media_org_idx');
            $table->dropColumn(['organization_id', 'uploaded_by']);
        });

        Schema::table('mixpost_settings', function (Blueprint $table) {
            $table->dropUnique('mixpost_settings_org_name_unq');
            $table->dropIndex('mixpost_settings_org_idx');
            $table->dropColumn('organization_id');
        });

        Schema::table('mixpost_imported_posts', function (Blueprint $table) {
            $table->dropIndex('mixpost_imported_posts_org_idx');
            $table->dropColumn('organization_id');
        });

        Schema::table('mixpost_facebook_insights', function (Blueprint $table) {
            $table->dropIndex('mixpost_fb_insights_org_idx');
            $table->dropColumn('organization_id');
        });

        Schema::table('mixpost_metrics', function (Blueprint $table) {
            $table->dropIndex('mixpost_metrics_org_idx');
            $table->dropColumn('organization_id');
        });

        Schema::table('mixpost_audience', function (Blueprint $table) {
            $table->dropIndex('mixpost_audience_org_idx');
            $table->dropColumn('organization_id');
        });
    }
};
