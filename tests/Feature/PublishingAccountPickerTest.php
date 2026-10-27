<?php
namespace Inovector\Mixpost\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Inovector\Mixpost\Services\Integrations\SharedSocialConnection;
use Tests\TestCase;

class PublishingAccountPickerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('sqlite');
        Schema::create('integration_catalog_settings', function (Blueprint $table) {
            $table->string('integration_key')->primary(); $table->boolean('enabled')->default(true);
        });
        Schema::create('mixpost_accounts', function (Blueprint $table) {
            $table->string('id')->primary(); $table->string('organization_id'); $table->string('provider');
            $table->string('provider_id'); $table->string('name'); $table->string('username')->nullable();
            $table->text('data')->nullable(); $table->text('media')->nullable(); $table->boolean('authorized')->default(true);
        });
        config()->set('integrations.nango.base_url', 'https://nango.test');
        config()->set('integrations.nango.secret_key', 'test-server-secret');
        $this->app->forgetInstance(\LaundryOS\Integrations\Modules\Nango\NangoClient::class);
        Http::preventStrayRequests();
    }

    public function test_catalog_uses_workspace_profile_names_without_leaking_credentials_or_owner_identity(): void
    {
        DB::table('mixpost_accounts')->insert(['id' => 'one', 'organization_id' => 'org-a', 'provider' => 'linkedin', 'provider_id' => 'person-1', 'name' => 'Publishing profile', 'username' => 'profile', 'data' => json_encode(['integration' => ['key' => 'li', 'connection_id' => 'saved']])]);
        Http::fake([
            'https://nango.test/integrations*' => Http::response(['data' => [['unique_key' => 'li', 'provider' => 'linkedin']]]),
            'https://nango.test/connections*' => Http::response(['connections' => [
                ['provider_config_key' => 'li', 'connection_id' => 'saved', 'tags' => ['organization_id' => 'org-a'], 'credentials' => ['access_token' => 'private-token']],
                ['provider_config_key' => 'li', 'connection_id' => 'unresolved', 'tags' => ['organization_id' => 'org-a'], 'end_user' => ['name' => 'Workspace owner']],
                ['provider_config_key' => 'li', 'connection_id' => 'foreign', 'tags' => ['organization_id' => 'org-b']],
                ['provider_config_key' => 'li', 'connection_id' => 'expired', 'tags' => ['organization_id' => 'org-a'], 'status' => 'expired'],
            ]]),
        ]);
        $result = app(SharedSocialConnection::class)->catalog('org-a');
        $this->assertCount(2, $result['connections']);
        $this->assertSame('Publishing profile', $result['connections'][0]['name']);
        $this->assertNull($result['connections'][1]['name']);
        $this->assertTrue($result['connections'][1]['name_is_placeholder']);
        $this->assertStringNotContainsString('private-token', json_encode($result));
        $this->assertStringNotContainsString('Workspace owner', json_encode($result));
    }

    public function test_profile_preview_marks_existing_profiles_and_only_returns_safe_fields(): void
    {
        DB::table('mixpost_accounts')->insert(['id' => 'one', 'organization_id' => 'org-a', 'provider' => 'linkedin', 'provider_id' => 'person-1', 'name' => 'Profile']);
        Http::fake([
            'https://nango.test/connections/*' => Http::response(['provider' => 'linkedin', 'tags' => ['organization_id' => 'org-a'], 'credentials' => ['access_token' => 'private-token']]),
            'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'person-1', 'name' => 'Profile', 'picture' => 'https://example.com/avatar.jpg']),
        ]);
        $targets = app(SharedSocialConnection::class)->targets('org-a', 'li', 'saved');
        $this->assertSame([['id' => 'person-1', 'name' => 'Profile', 'username' => '', 'image' => 'https://example.com/avatar.jpg', 'linked' => true]], $targets);
        DB::table('mixpost_accounts')->update(['organization_id' => 'org-b']);
        $this->assertFalse(app(SharedSocialConnection::class)->targets('org-a', 'li', 'saved')[0]['linked']);
    }
}
