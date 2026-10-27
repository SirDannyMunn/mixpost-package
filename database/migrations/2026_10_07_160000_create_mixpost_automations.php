<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mixpost_automations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('created_by')->nullable();
            $table->string('name');
            $table->string('type', 20);
            $table->boolean('enabled')->default(false);
            $table->string('mode', 20)->default('draft');
            $table->json('config');
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_runs')->default(100);
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('last_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('mixpost_automation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('automation_id');
            $table->foreign('automation_id')->references('id')->on('mixpost_automations')->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->uuid('post_id')->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['automation_id', 'event_key']);
        });
        Schema::create('mixpost_scheduler_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('request_key', 128);
            $table->string('payload_hash', 64);
            $table->uuid('post_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mixpost_scheduler_requests');
        Schema::dropIfExists('mixpost_automation_runs');
        Schema::dropIfExists('mixpost_automations');
    }
};
