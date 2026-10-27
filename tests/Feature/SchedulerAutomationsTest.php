<?php

namespace Inovector\Mixpost\Tests\Feature;

use App\Models\{Organization, OrganizationMember, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Actions\PublishPost;
use Inovector\Mixpost\Models\{Account, Automation, ImportedPost, Post};
use Inovector\Mixpost\Services\Automations\{AutomationRunner, AutomationService, FeedReader};
use Inovector\Mixpost\Services\Scheduler\SchedulerService;
use Laravel\Sanctum\Sanctum;
use LaundryOS\Mcp\Services\SchedulerMcpService;
use Tests\TestCase;

class SchedulerAutomationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Account $account;
    private SchedulerService $posts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();
        OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        Sanctum::actingAs($this->user);
        $this->account = Account::create(['organization_id' => $this->org->id, 'name' => 'Example', 'provider' => 'twitter', 'provider_id' => 'test', 'authorized' => true, 'access_token' => [], 'data' => []]);
        $this->posts = app(SchedulerService::class);
    }

    private function payload(string $key = 'draft-one', string $body = 'Hello world'): array
    {
        return ['idempotency_key' => $key, 'accounts' => [$this->account->id], 'versions' => [['account_id' => null, 'is_original' => true, 'content' => [['body' => $body, 'media' => []]]]]];
    }

    private function draft(string $key = 'draft-one'): array { return $this->posts->draft($this->org->id, $this->user->id, $this->payload($key)); }

    private function rule(string $type, array $config, array $extra = []): Automation
    {
        return app(AutomationService::class)->save($this->org->id, $this->user->id, [
            'name' => 'Test '.$type, 'type' => $type, 'mode' => 'draft', 'enabled' => true,
            'next_run_at' => now()->subMinute()->toIso8601String(), 'max_runs' => 5,
            'config' => ['interval_minutes' => $type === 'evergreen' ? 1440 : 15, ...$config], ...$extra,
        ]);
    }

    public function test_draft_retry_is_idempotent_and_rejects_changed_content(): void
    {
        $first = $this->draft();
        $this->assertSame($first['id'], $this->draft()['id']);
        $this->assertSame(1, Post::count());
        $this->expectException(ValidationException::class);
        $this->posts->draft($this->org->id, $this->user->id, $this->payload('draft-one', 'Different'));
    }

    public function test_foreign_accounts_cannot_be_attached_to_drafts(): void
    {
        $other = Organization::factory()->create();
        $this->expectException(ValidationException::class);
        $this->posts->draft($other->id, $this->user->id, $this->payload());
    }

    public function test_concurrent_edits_in_the_same_second_require_a_fresh_revision(): void
    {
        $draft = $this->draft();
        $this->posts->draft($this->org->id, $this->user->id, [...$this->payload('unused', 'New content'), 'expected_revision' => $draft['revision']], $draft['id']);
        $this->expectException(ValidationException::class);
        $this->posts->draft($this->org->id, $this->user->id, [...$this->payload('unused', 'Stale content'), 'expected_revision' => $draft['revision']], $draft['id']);
    }

    public function test_validation_prevents_disconnected_accounts_and_missing_video_from_scheduling(): void
    {
        $draft = $this->draft();
        $this->account->update(['authorized' => false]);
        $this->assertFalse($this->posts->validate($this->org->id, $draft['id'])['valid']);
        $this->account->update(['authorized' => true, 'provider' => 'youtube']);
        $result = $this->posts->validate($this->org->id, $draft['id']);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('video is required', implode(' ', $result['errors']));
        $this->expectException(ValidationException::class);
        $this->posts->schedule($this->org->id, $draft['id'], now()->addHour()->toIso8601String());
    }

    public function test_runner_reports_errors_without_blocking_other_due_rules(): void
    {
        $this->mock(FeedReader::class)->shouldReceive('read')->andThrow(new \RuntimeException('Unavailable'));
        $rss = $this->rule('rss', ['feed_url' => 'https://example.com/feed', 'accounts' => [$this->account->id], 'template' => '{title}']);
        $draft = $this->draft();
        $evergreen = $this->rule('evergreen', ['post_ids' => [$draft['id']]]);
        app(AutomationRunner::class)->due();
        $this->assertNotNull($rss->fresh()->last_error);
        $this->assertSame(1, $evergreen->fresh()->run_count);
    }

    public function test_mcp_checks_membership_even_for_read_operations(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SchedulerMcpService::class)->execute('accounts', ['organization_id' => Organization::factory()->create()->id]);
    }

    public function test_schedule_cancel_and_publish_use_real_queue_not_simulated_success(): void
    {
        Bus::fake();
        $draft = $this->draft();
        $scheduled = $this->posts->schedule($this->org->id, $draft['id'], now()->addHour()->toIso8601String());
        $this->assertSame('SCHEDULED', $scheduled['status']);
        $this->assertNull($scheduled['published_at']);
        $post = $this->posts->post($this->org->id, $draft['id']);
        $this->posts->cancel($this->org->id, $draft['id']);
        app(PublishPost::class)($post); // stale scheduled model cannot dispatch after cancellation
        Bus::assertBatchCount(0);
        app(SchedulerMcpService::class)->execute('publish', ['organization_id' => $this->org->id, 'post_id' => $draft['id']]);
        $this->travel(2)->seconds();
        app(PublishPost::class)($post);
        app(PublishPost::class)($post);
        Bus::assertBatchCount(1);
        $this->assertSame('PUBLISHING', $this->posts->serialize($post->fresh())['status']);
    }

    public function test_conflicting_slots_are_rejected(): void
    {
        $a = $this->draft('a'); $b = $this->draft('b');
        $at = now()->addDay()->toIso8601String();
        $this->posts->schedule($this->org->id, $a['id'], $at);
        $this->expectException(ValidationException::class);
        $this->posts->schedule($this->org->id, $b['id'], $at);
    }

    public function test_evergreen_rotates_stops_at_limit_and_does_not_repeat_a_tick(): void
    {
        $a = $this->draft('a'); $b = $this->posts->draft($this->org->id, $this->user->id, $this->payload('b', 'Second'));
        $rule = $this->rule('evergreen', ['post_ids' => [$a['id'], $b['id']]], ['max_runs' => 2]);
        $runner = app(AutomationRunner::class);
        $runner->run($rule); $runner->run($rule);
        $this->assertSame(3, Post::count());
        $this->travel(1)->days();
        $runner->run($rule->fresh());
        $this->assertSame(4, Post::count());
        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame('Second', Post::latest('created_at')->first()->versions()->first()->content[0]['body']);
    }

    public function test_paused_and_expired_rules_do_not_generate_posts(): void
    {
        $post = $this->draft();
        $rule = $this->rule('evergreen', ['post_ids' => [$post['id']]], ['enabled' => false]);
        app(AutomationRunner::class)->run($rule);
        $this->assertSame(1, Post::count());
        $rule->update(['enabled' => true, 'expires_at' => now()->subHour()]);
        app(AutomationRunner::class)->run($rule->fresh());
        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame(1, Post::count());
    }

    public function test_rss_baselines_existing_items_and_only_generates_new_entries_once(): void
    {
        $old = ['key' => 'old', 'title' => 'Old', 'link' => 'https://example.com/old', 'summary' => ''];
        $new = ['key' => 'new', 'title' => 'New', 'link' => 'https://example.com/new', 'summary' => ''];
        $feed = $this->mock(FeedReader::class);
        $feed->shouldReceive('read')->andReturn([$old], [$old, $new], [$old, $new]);
        $rule = $this->rule('rss', ['feed_url' => 'https://example.com/feed', 'accounts' => [$this->account->id], 'template' => '{title}\n{url}']);
        $runner = app(AutomationRunner::class); $runner->run($rule);
        $this->assertSame(0, Post::count());
        $this->travel(16)->minutes(); $runner->run($rule->fresh());
        $this->assertSame(1, Post::count());
        $this->travel(16)->minutes(); $runner->run($rule->fresh());
        $this->assertSame(1, Post::count());
        $this->assertSame(1, $rule->fresh()->run_count);
    }

    public function test_rss_multiple_entries_get_distinct_real_schedule_slots(): void
    {
        $this->mock(FeedReader::class)->shouldReceive('read')->andReturn([
            ['key' => 'a', 'title' => 'A', 'link' => 'https://example.com/a', 'summary' => ''],
            ['key' => 'b', 'title' => 'B', 'link' => 'https://example.com/b', 'summary' => ''],
        ]);
        $rule = $this->rule('rss', ['feed_url' => 'https://example.com/feed', 'accounts' => [$this->account->id], 'template' => '{title} {url}', 'include_existing' => true], ['mode' => 'schedule']);
        app(AutomationRunner::class)->run($rule);
        $this->assertSame(2, Post::scheduled()->count());
        $this->assertSame(2, Post::pluck('scheduled_at')->unique()->count());
    }

    private function published(): array
    {
        $draft = $this->draft(); $post = $this->posts->post($this->org->id, $draft['id']);
        $post->setPublished(); $post->accounts()->updateExistingPivot($this->account->id, ['provider_post_id' => 'remote-123']);
        return $draft;
    }

    public function test_engagement_metric_triggers_once_using_imported_post_metrics(): void
    {
        $post = $this->published();
        ImportedPost::create(['organization_id' => $this->org->id, 'account_id' => $this->account->id, 'provider_post_id' => 'remote-123', 'content' => [], 'metrics' => ['likes' => 100], 'created_at' => now()]);
        $rule = $this->rule('engagement', ['source_post_id' => $post['id'], 'account_id' => $this->account->id, 'trigger' => 'metric', 'metric' => 'likes', 'threshold' => 100, 'followup' => $this->payload('unused', 'Thanks!')]);
        app(AutomationRunner::class)->run($rule);
        $this->travel(16)->minutes(); app(AutomationRunner::class)->run($rule->fresh());
        $this->assertSame(2, Post::count());
        $this->assertSame(1, $rule->fresh()->run_count);
    }

    public function test_comment_events_require_membership_and_dedupe_by_event_id(): void
    {
        $post = $this->published();
        $rule = $this->rule('engagement', ['source_post_id' => $post['id'], 'account_id' => $this->account->id, 'trigger' => 'comment_keyword', 'keyword' => 'guide', 'followup' => $this->payload('unused', 'Here is the guide')]);
        $event = ['post_id' => $post['id'], 'account_id' => $this->account->id, 'event_id' => 'comment-123', 'text' => 'GUIDE please'];
        $this->postJson('/api/v1/social/automations/engagement-events', $event, ['X-Organization-Id' => Organization::factory()->create()->id])->assertForbidden();
        $headers = ['X-Organization-Id' => $this->org->id];
        $this->postJson('/api/v1/social/automations/engagement-events', $event, $headers)->assertOk()->assertJsonCount(1, 'created_post_ids');
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/social/automations/engagement-events', $event, $headers)->assertOk()->assertJsonCount(0, 'created_post_ids');
        $this->assertSame(1, $rule->fresh()->run_count);
    }

    public function test_automation_api_rejects_foreign_posts_and_stale_edits(): void
    {
        $post = $this->draft(); $rule = $this->rule('evergreen', ['post_ids' => [$post['id']]]);
        app(AutomationService::class)->toggle($this->org->id, $rule->id, false);
        $this->putJson('/api/v1/social/automations/'.$rule->id, $rule->toArray(), ['X-Organization-Id' => $this->org->id])->assertUnprocessable();
    }

    public function test_past_posts_are_scheduled_and_collected_once_without_publishing_drafts(): void
    {
        Bus::fake();
        $draft = $this->draft();
        $untouched = $this->draft('leave-as-draft');
        $this->posts->schedule($this->org->id, $draft['id'], now()->subDays(2)->toIso8601String());
        $this->artisan('mixpost:run-scheduled-posts')->assertSuccessful();
        $this->artisan('mixpost:run-scheduled-posts')->assertSuccessful();
        Bus::assertBatchCount(1);
        $this->assertSame('DRAFT', $this->posts->post($this->org->id, $untouched['id'])->status->name);
    }

    public function test_post_form_converts_selected_timezone_and_accepts_past_scheduling(): void
    {
        $draft = $this->draft();
        $headers = ['X-Organization-Id' => $this->org->id];
        $this->putJson('/api/v1/social/posts/'.$draft['id'], [
            ...$this->payload(), 'tags' => [], 'date' => '2020-07-15', 'time' => '09:00', 'timezone' => 'America/New_York',
        ], $headers)->assertSuccessful();
        $post = $this->posts->post($this->org->id, $draft['id']);
        $this->assertSame('2020-07-15 13:00', $post->scheduled_at->format('Y-m-d H:i'));
        $this->postJson('/api/v1/social/posts/schedule/'.$draft['id'], ['postNow' => false], $headers)->assertSuccessful();
        $this->assertSame('SCHEDULED', $post->fresh()->status->name);
        $this->putJson('/api/v1/social/posts/'.$draft['id'], [
            ...$this->payload(), 'tags' => [], 'date' => '2020-07-15', 'time' => '09:00', 'timezone' => 'Invalid/Zone',
        ], $headers)->assertUnprocessable();
    }

    public function test_history_errors_are_actionable_and_post_now_uses_the_current_time(): void
    {
        $draft = $this->draft();
        $post = $this->posts->post($this->org->id, $draft['id']);
        $headers = ['X-Organization-Id' => $this->org->id];
        foreach ([\Inovector\Mixpost\Enums\PostStatus::FAILED, \Inovector\Mixpost\Enums\PostStatus::PUBLISHED, \Inovector\Mixpost\Enums\PostStatus::DELIVERED] as $status) {
            $post->forceFill(['status' => $status])->save();
            $this->putJson('/api/v1/social/posts/'.$draft['id'], [
                ...$this->payload(), 'tags' => [], 'date' => '2030-10-23', 'time' => '09:00',
            ], $headers)->assertUnprocessable()->assertJsonPath('errors.post.0', 'This post is in your publishing history. Duplicate it as a draft to edit or schedule it again.');
            $this->postJson('/api/v1/social/posts/schedule/'.$draft['id'], ['postNow' => true], $headers)
                ->assertUnprocessable()->assertJsonPath('errors.post.0', 'This post is in your publishing history. Duplicate it as a draft to edit or schedule it again.');
        }
        $post->setDraft();
        $post->scheduled_at = now()->addYear();
        $post->save();
        $this->postJson('/api/v1/social/posts/schedule/'.$draft['id'], ['postNow' => true], $headers)->assertSuccessful();
        $post->refresh();
        $this->assertSame('SCHEDULED', $post->status->name);
        $this->assertTrue($post->scheduled_at->lessThanOrEqualTo(now()->utc()));
        $this->assertTrue($post->scheduled_at->greaterThan(now()->utc()->subMinute()));
    }
}
