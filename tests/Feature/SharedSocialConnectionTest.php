<?php

namespace Inovector\Mixpost\Tests\Feature;

use App\Models\{Organization, OrganizationMember, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Services\Integrations\SharedSocialConnection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedSocialConnectionTest extends TestCase
{
    use RefreshDatabase;
    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();
        OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        Sanctum::actingAs($this->user);
        config()->set('integrations.nango.base_url', 'https://nango.test');
        config()->set('integrations.nango.secret_key', 'test-server-secret');
        $this->app->forgetInstance(\LaundryOS\Integrations\Modules\Nango\NangoClient::class);
        Http::preventStrayRequests();
    }

    private function fake(string $provider = 'linkedin', ?string $org = null): void
    {
        Http::fake([
            'https://nango.test/connections/*' => fn ($request) => str_contains($request->url(), '/missing?') ? Http::response([], 404) : Http::response(['provider' => $provider, 'tags' => ['organization_id' => $org ?? $this->org->id], 'credentials' => ['access_token' => 'fresh-token', 'refresh_token' => 'never-copy-me']]),
            'https://api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'person-1', 'name' => 'Test Person']),
            'https://graph.facebook.com/*' => Http::response(['data' => [['id' => 'page-1', 'name' => 'Test Page', 'access_token' => 'page-token']]]),
        ]);
    }

    public function test_link_is_idempotent_and_stores_only_a_reference(): void
    {
        $this->fake();
        $service = app(SharedSocialConnection::class);
        $account = $service->link($this->org->id, $this->user->id, 'custom-linkedin', 'connection-1', 'person-1');
        $again = $service->link($this->org->id, $this->user->id, 'custom-linkedin', 'connection-1', 'person-1');
        $this->assertSame($account->id, $again->id);
        $this->assertSame([], $account->access_token->toArray());
        $this->assertStringNotContainsString('fresh-token', json_encode($account->getAttributes()));
        $this->assertTrue($account->isServiceActive());
        $adapter = $service->connect($account);
        $this->assertSame('fresh-token', $adapter->getAccessToken()['access_token']);
        $this->assertArrayNotHasKey('refresh_token', $adapter->getAccessToken());
    }

    public function test_other_workspace_connection_is_rejected_before_refresh(): void
    {
        $this->fake('linkedin', 'another-workspace');
        $this->withHeader('X-Organization-Id', $this->org->id)->postJson('/api/v1/social/accounts/integrations/targets', ['key' => 'linkedin', 'connection_id' => 'connection-1'])->assertNotFound();
        Http::assertSentCount(1);
        $this->assertSame(0, Account::count());
    }

    public function test_profile_selection_is_verified_and_credentials_never_reach_the_browser(): void
    {
        $this->fake();
        $response = $this->withHeader('X-Organization-Id', $this->org->id)->postJson('/api/v1/social/accounts/integrations/targets', ['key' => 'linkedin', 'connection_id' => 'connection-1'])->assertOk();
        $response->assertExactJson(['targets' => [['id' => 'person-1', 'name' => 'Test Person', 'username' => '']]]);
        $this->postJson('/api/v1/social/accounts/integrations/link', ['key' => 'linkedin', 'connection_id' => 'connection-1', 'target_id' => 'foreign-person'])->assertUnprocessable();
        $this->assertSame(0, Account::count());
    }

    public function test_facebook_page_token_is_resolved_in_memory(): void
    {
        $this->fake('facebook');
        $service = app(SharedSocialConnection::class);
        $account = $service->link($this->org->id, $this->user->id, 'facebook', 'connection-1', 'page-1');
        $this->assertSame('page-token', $service->connect($account)->getAccessToken()['page_access_token']);
        $this->assertSame([], $account->fresh()->access_token->toArray());
    }

    public function test_unlink_does_not_revoke_shared_connection(): void
    {
        $this->fake();
        $account = app(SharedSocialConnection::class)->link($this->org->id, $this->user->id, 'linkedin', 'connection-1', 'person-1');
        $this->withHeader('X-Organization-Id', $this->org->id)->deleteJson('/api/v1/social/accounts/'.$account->uuid)->assertOk();
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSame(0, Account::count());
    }

    public function test_changed_profile_cannot_publish_under_the_old_account(): void
    {
        $this->fake();
        $service = app(SharedSocialConnection::class);
        $account = $service->link($this->org->id, $this->user->id, 'linkedin', 'connection-1', 'person-1');
        $account->provider_id = 'different-person';
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->connect($account);
    }

    public function test_publishing_uses_the_shared_token_and_records_credential_failure(): void
    {
        $this->fake();
        Http::fake(['https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:test'])]);
        $account = app(SharedSocialConnection::class)->link($this->org->id, $this->user->id, 'linkedin', 'connection-1', 'person-1');
        $scheduler = app(\Inovector\Mixpost\Services\Scheduler\SchedulerService::class);
        $draft = $scheduler->draft($this->org->id, $this->user->id, ['idempotency_key' => 'shared-publish', 'accounts' => [$account->id], 'versions' => [['is_original' => true, 'account_id' => null, 'content' => [['body' => 'Test post', 'media' => []]]]]]);
        $post = $scheduler->post($this->org->id, $draft['id']);
        $response = app(\Inovector\Mixpost\Actions\AccountPublishPost::class)($account, $post);
        $this->assertFalse($response->hasError());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/posts' && $request->hasHeader('Authorization', 'Bearer fresh-token'));
        $account->data = ['integration' => ['key' => 'linkedin', 'connection_id' => 'missing']];
        $this->assertTrue(app(\Inovector\Mixpost\Actions\AccountPublishPost::class)($account, $post)->hasError());
        $this->assertTrue($post->fresh()->hasErrors());
    }

    public function test_tiktok_inbox_delivery_is_not_reported_as_publication(): void
    {
        $this->fake('tiktok-personal');
        Http::fake([
            'https://open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => 'creator-1', 'display_name' => 'Test creator']]]),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'SEND_TO_USER_INBOX']]),
        ]);
        $account = app(SharedSocialConnection::class)->link($this->org->id, $this->user->id, 'tiktok-personal', 'connection-1', 'creator-1');
        $scheduler = app(\Inovector\Mixpost\Services\Scheduler\SchedulerService::class);
        $draft = $scheduler->draft($this->org->id, $this->user->id, ['idempotency_key' => 'tiktok-inbox', 'accounts' => [$account->id], 'versions' => [['is_original' => true, 'account_id' => null, 'options' => ['tiktok' => ['mode' => 'draft', 'consent' => true]], 'content' => [['body' => 'Test video', 'media' => []]]]]]);
        $this->assertTrue($draft['versions'][0]['options']['tiktok']['consent']);
        $post = $scheduler->post($this->org->id, $draft['id']);
        $post->setScheduleProcessing();
        $post->insertProviderData($account, new \Inovector\Mixpost\Support\SocialProviderResponse(\Inovector\Mixpost\Enums\SocialProviderResponseStatus::OK, ['id' => 'publish-1', 'data' => ['delivery_status' => 'PROCESSING_DOWNLOAD', 'delivery_mode' => 'draft']]));
        (new \Inovector\Mixpost\Jobs\CheckTikTokPostStatus($post))->handle();
        $this->assertSame(\Inovector\Mixpost\Enums\PostStatus::DELIVERED, $post->fresh()->status);
        $this->assertNull($post->fresh()->published_at);
        $this->assertTrue($post->fresh()->isInHistory());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
    }
}
