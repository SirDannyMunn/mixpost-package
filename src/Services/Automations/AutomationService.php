<?php

namespace Inovector\Mixpost\Services\Automations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Models\Automation;
use Inovector\Mixpost\Services\Scheduler\SchedulerService;

class AutomationService
{
    public function __construct(private readonly SchedulerService $scheduler) {}

    public function list(string $org): array
    {
        return Automation::where('organization_id', $org)->latest()->limit(200)->get()->toArray();
    }

    public function get(string $org, string $id, bool $lock = false): Automation
    {
        return Automation::where('organization_id', $org)->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function save(string $org, ?string $actor, array $data, ?string $id = null): Automation
    {
        $v = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['evergreen', 'rss', 'engagement'])],
            'enabled' => ['sometimes', 'boolean'], 'mode' => ['required', Rule::in(['draft', 'schedule'])],
            'next_run_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after:now'],
            'max_runs' => ['required', 'integer', 'min:1', 'max:10000'], 'config' => ['required', 'array'],
            'revision' => [$id ? 'required' : 'sometimes', 'integer', 'min:1'],
        ])->validate();
        $config = $this->config($org, $v['type'], $v['config']);
        if ($v['type'] === 'evergreen' && empty($v['next_run_at'])) throw ValidationException::withMessages(['next_run_at' => 'Choose the first repeat time.']);
        return DB::transaction(function () use ($org, $actor, $id, $v, $config) {
            $rule = $id ? $this->get($org, $id, true) : new Automation(['organization_id' => $org, 'created_by' => $actor]);
            if ($id && ($rule->type !== $v['type'] || $rule->revision !== $v['revision'])) throw ValidationException::withMessages(['revision' => 'Rule type cannot change and edits must use its latest revision.']);
            $rule->fill(collect($v)->except('revision', 'config')->all());
            if ($id && $v['type'] === 'rss' && ($rule->config['feed_url'] ?? '') !== $config['feed_url']) $rule->last_run_at = null;
            $rule->config = $config;
            $rule->revision = $id ? $rule->revision + 1 : 1;
            $rule->next_run_at = !empty($v['next_run_at']) ? Carbon::parse($v['next_run_at'])->utc() : now()->utc();
            $rule->last_error = null;
            $rule->save();
            return $rule;
        });
    }

    public function toggle(string $org, string $id, bool $enabled): Automation
    {
        return DB::transaction(function () use ($org, $id, $enabled) {
            $rule = $this->get($org, $id, true);
            if ($enabled && (($rule->expires_at && $rule->expires_at->isPast()) || $rule->run_count >= $rule->max_runs)) throw ValidationException::withMessages(['rule' => 'Extend the expiry or repeat limit before resuming.']);
            $rule->enabled = $enabled;
            $rule->revision++;
            $rule->save();
            return $rule;
        });
    }

    public function runs(string $org, string $id): array
    {
        $rule = $this->get($org, $id);
        return DB::table('mixpost_automation_runs')->where('automation_id', $rule->id)->orderByDesc('created_at')->limit(100)->get()->toArray();
    }

    private function config(string $org, string $type, array $c): array
    {
        $common = ['interval_minutes' => ['required', 'integer', 'min:15', 'max:525600'], 'delay_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080']];
        $rules = match ($type) {
            'evergreen' => ['post_ids' => ['required', 'array', 'min:1', 'max:100'], 'post_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('mixpost_posts', 'uuid')->where('organization_id', $org)]],
            'rss' => ['feed_url' => ['required', 'url:http,https', 'max:2048'], 'accounts' => ['required', 'array', 'min:1'], 'accounts.*' => ['uuid', 'distinct', Rule::exists('mixpost_accounts', 'id')->where('organization_id', $org)], 'template' => ['required', 'string', 'max:4000'], 'include_existing' => ['sometimes', 'boolean']],
            'engagement' => ['source_post_id' => ['required', 'uuid', Rule::exists('mixpost_posts', 'uuid')->where('organization_id', $org)], 'account_id' => ['required', 'uuid', Rule::exists('mixpost_accounts', 'id')->where('organization_id', $org)], 'trigger' => ['required', Rule::in(['metric', 'comment_keyword'])], 'metric' => ['required_if:trigger,metric', Rule::in(['likes', 'replies', 'retweets', 'impressions', 'favourites', 'reblogs'])], 'threshold' => ['required_if:trigger,metric', 'integer', 'min:1'], 'keyword' => ['required_if:trigger,comment_keyword', 'string', 'min:1', 'max:100'], 'followup' => ['required', 'array']],
        };
        $v = Validator::make($c, [...$common, ...$rules])->validate();
        if ($type === 'evergreen' && $v['interval_minutes'] < 1440) throw ValidationException::withMessages(['interval_minutes' => 'Evergreen repeats must be at least one day apart.']);
        if ($type === 'engagement') {
            $post = $this->scheduler->post($org, $v['source_post_id']);
            if (!$post->isPublished() || !$post->accounts()->where('mixpost_accounts.id', $v['account_id'])->whereNotNull('mixpost_post_accounts.provider_post_id')->exists()) throw ValidationException::withMessages(['source_post_id' => 'Choose a published post on this account.']);
            $v['followup'] = $this->scheduler->validatePayload($org, $v['followup']);
            if (!$v['followup']['accounts']) throw ValidationException::withMessages(['followup' => 'Choose a follow-up account.']);
        }
        return $v;
    }
}
