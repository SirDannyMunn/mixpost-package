<?php

namespace Inovector\Mixpost\Services\Automations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Models\{Automation, ImportedPost};
use Inovector\Mixpost\Services\Scheduler\SchedulerService;
use Throwable;

class AutomationRunner
{
    public function __construct(private readonly SchedulerService $scheduler, private readonly FeedReader $feeds) {}

    public function due(): int
    {
        $count = 0;
        Automation::where('enabled', true)->where('next_run_at', '<=', now()->utc())->orderBy('next_run_at')->limit(100)->get()->each(function ($rule) use (&$count) {
            try { $this->run($rule); $count++; }
            catch (Throwable $error) {
                $message = $error instanceof ValidationException ? implode(' ', array_merge(...array_values($error->errors()))) : 'Automation failed; check source availability and configuration.';
                Automation::whereKey($rule->id)->where('revision', $rule->revision)->update(['last_error' => mb_substr($message, 0, 1000), 'next_run_at' => now()->utc()->addMinutes(15)]);
                report($error);
            }
        });
        return $count;
    }

    public function run(Automation $snapshot): void
    {
        if (!$snapshot->enabled || $snapshot->next_run_at?->isFuture()) return;
        // Remote I/O happens outside database locks. Recheck revision and due time afterward.
        $items = $snapshot->type === 'rss' ? $this->feeds->read($snapshot->config['feed_url']) : [];
        DB::transaction(function () use ($snapshot, $items) {
            $rule = Automation::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if (!$this->active($rule) || $rule->revision !== $snapshot->revision || $rule->next_run_at?->isFuture()) return;
            $org = (string) $rule->organization_id;
            $c = $rule->config;
            if ($rule->type === 'evergreen') {
                $source = $this->scheduler->post($org, $c['post_ids'][$rule->run_count % count($c['post_ids'])]);
                $data = $this->scheduler->serialize($source);
                $data['accounts'] = array_column($data['accounts'], 'id');
                $this->produce($rule, 'repeat:'.$rule->run_count, $data);
            } elseif ($rule->type === 'rss') {
                $baseline = !$rule->last_run_at && !($c['include_existing'] ?? false);
                foreach ($items as $item) {
                    if (!$this->active($rule)) break;
                    $key = 'feed:'.$item['key'];
                    if ($baseline) { $this->record($rule, $key, 'baseline'); continue; }
                    $body = strtr($c['template'], ['{title}' => $item['title'], '{url}' => $item['link'], '{summary}' => $item['summary']]);
                    $this->produce($rule, $key, ['accounts' => $c['accounts'], 'versions' => [['account_id' => null, 'is_original' => true, 'content' => [['body' => $body, 'media' => []]]]]]);
                }
            } elseif ($c['trigger'] === 'metric') {
                $post = $this->scheduler->post($org, $c['source_post_id']);
                $account = $post->accounts()->where('mixpost_accounts.id', $c['account_id'])->firstOrFail();
                $import = ImportedPost::where('account_id', $account->id)->where('provider_post_id', $account->pivot->provider_post_id)->first();
                if ($import && (int) data_get($import->metrics, $c['metric'], 0) >= $c['threshold']) $this->produce($rule, 'threshold', $c['followup']);
            }
            $rule->last_run_at = now()->utc();
            $rule->next_run_at = now()->utc()->addMinutes($c['interval_minutes']);
            $rule->last_error = null;
            $rule->save();
        });
    }

    /** Called by authenticated collectors; no public unauthenticated webhook accepts engagement. */
    public function engagement(string $org, array $event): array
    {
        $post = $this->scheduler->post($org, $event['post_id']);
        abort_unless($post->isPublished() && $post->accounts()->where('mixpost_accounts.id', $event['account_id'])->exists(), 422, 'Event must reference a published post and its account.');
        $produced = [];
        foreach (Automation::where('organization_id', $org)->where('type', 'engagement')->where('enabled', true)->get() as $candidate) {
            DB::transaction(function () use ($candidate, $event, &$produced) {
                $rule = Automation::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $c = $rule->config;
                if (!$this->active($rule) || $c['source_post_id'] !== $event['post_id'] || $c['account_id'] !== $event['account_id']) return;
                if ($c['trigger'] === 'metric') {
                    if ((int) data_get($event, 'metrics.'.$c['metric'], 0) < $c['threshold']) return;
                    $key = 'threshold';
                } else {
                    if (!Str::contains(mb_strtolower($event['text'] ?? ''), mb_strtolower($c['keyword']))) return;
                    $key = 'comment:'.$event['event_id'];
                }
                $lastOutput = DB::table('mixpost_automation_runs')->where('automation_id', $rule->id)->whereIn('status', ['draft', 'scheduled'])->latest('created_at')->value('created_at');
                if ($lastOutput && \Illuminate\Support\Carbon::parse($lastOutput)->addMinutes($c['interval_minutes'])->isFuture()) return;
                $id = $this->produce($rule, $key, $c['followup']);
                if ($id) { $produced[] = $id; $rule->last_run_at = now()->utc(); $rule->save(); }
            });
        }
        return ['created_post_ids' => $produced];
    }

    private function produce(Automation $rule, string $key, array $payload): ?string
    {
        foreach ($payload['versions'] ?? [] as $index => $version) {
            if (isset($version['options']['tiktok'])) $payload['versions'][$index]['options']['tiktok']['consent'] = false;
        }
        if (!$this->record($rule, $key, 'pending')) return null;
        $post = $this->scheduler->draft((string) $rule->organization_id, $rule->created_by, [...$payload, 'idempotency_key' => 'automation:'.$rule->id.':'.hash('sha256', $key)]);
        if ($rule->mode === 'schedule') {
            $at = now()->utc()->startOfMinute()->addMinutes($rule->config['delay_minutes'] ?? 5);
            // Serialize the whole slot search across rules sharing any account.
            \Inovector\Mixpost\Models\Account::forOrganization($rule->organization_id)->whereIn('id', $payload['accounts'])->orderBy('id')->lockForUpdate()->get();
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $conflict = \Inovector\Mixpost\Models\Post::forOrganization($rule->organization_id)->scheduled()->where('scheduled_at', $at)
                    ->whereHas('accounts', fn ($q) => $q->whereIn('mixpost_accounts.id', $payload['accounts']))->exists();
                if (!$conflict) break;
                $at->addMinutes($rule->config['delay_minutes'] ?? 5);
            }
            $post = $this->scheduler->schedule((string) $rule->organization_id, $post['id'], $at->toIso8601String());
        }
        DB::table('mixpost_automation_runs')->where('automation_id', $rule->id)->where('event_key', hash('sha256', $key))->update(['status' => strtolower($post['status']), 'post_id' => $post['id'], 'updated_at' => now()]);
        $rule->run_count++;
        if ($rule->run_count >= $rule->max_runs) $rule->enabled = false;
        $rule->save();
        return $post['id'];
    }

    private function record(Automation $rule, string $key, string $status): bool
    {
        return (bool) DB::table('mixpost_automation_runs')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'automation_id' => $rule->id, 'event_key' => hash('sha256', $key),
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function active(Automation $rule): bool
    {
        if (!$rule->enabled) return false;
        if (($rule->expires_at && $rule->expires_at->isPast()) || $rule->run_count >= $rule->max_runs) {
            $rule->enabled = false; $rule->save(); return false;
        }
        return true;
    }
}
