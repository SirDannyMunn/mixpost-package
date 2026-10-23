<?php

namespace Inovector\Mixpost\Services\Scheduler;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Models\{Account, Media, Post};
use Inovector\Mixpost\Support\{PostContentParser, PostScheduleGuard};

class SchedulerService
{
    public function accounts(string $org): array
    {
        return Account::forOrganization($org)->orderBy('name')->get()->map(fn ($a) => [
            'id' => $a->id, 'name' => $a->name, 'provider' => $a->provider,
            'authorized' => $a->authorized, 'capabilities' => $a->postConfigs(),
        ])->all();
    }

    public function post(string $org, string $id, bool $lock = false): Post
    {
        return Post::forOrganization($org)->where('uuid', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function list(string $org, array $filters): array
    {
        $v = Validator::make($filters, [
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['DRAFT', 'SCHEDULED', 'PUBLISHED', 'FAILED', 'DELIVERED'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $query = Post::forOrganization($org)->with('accounts', 'versions');
        if (!empty($v['from'])) $query->where('scheduled_at', '>=', Carbon::parse($v['from'])->utc());
        if (!empty($v['to'])) $query->where('scheduled_at', '<=', Carbon::parse($v['to'])->utc());
        if (!empty($v['status'])) $query->where('status', constant(PostStatus::class.'::'.$v['status'])->value);
        $page = $query->orderByDesc('created_at')->paginate($v['limit'] ?? 50, ['*'], 'page', $v['page'] ?? 1);
        return ['posts' => $page->getCollection()->map(fn ($p) => $this->serialize($p))->all(), 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()];
    }

    public function draft(string $org, ?string $actor, array $data, ?string $id = null): array
    {
        $v = $this->validatePayload($org, $data);
        $v['versions'] = array_map(function (array $version): array {
            if (isset($version['options']['tiktok']) && is_array($version['options']['tiktok'])) {
                $version['options']['tiktok'] = \Inovector\Mixpost\Support\TikTokPostOptions::normalize($version['options']['tiktok']);
            }
            return $version;
        }, $v['versions']);
        if (!$id) Validator::make($data, ['idempotency_key' => ['required', 'string', 'max:128']])->validate();
        return DB::transaction(function () use ($org, $actor, $data, $v, $id) {
            if (!$id) {
                $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
                DB::table('mixpost_scheduler_requests')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'organization_id' => $org, 'request_key' => $data['idempotency_key'],
                    'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $request = DB::table('mixpost_scheduler_requests')->where('organization_id', $org)->where('request_key', $data['idempotency_key'])->lockForUpdate()->first();
                if ($request->payload_hash !== $hash) throw ValidationException::withMessages(['idempotency_key' => 'This key was used for different content.']);
                if ($request->post_id) return $this->serialize(Post::forOrganization($org)->findOrFail($request->post_id));
            }
            $post = $id ? $this->post($org, $id, true) : new Post(['organization_id' => $org, 'created_by' => $actor]);
            if ($id) {
                $this->editable($post);
                Validator::make($data, ['expected_revision' => ['required', 'string']])->validate();
                if (!hash_equals($this->serialize($post)['revision'], $data['expected_revision'])) throw ValidationException::withMessages(['post' => 'Post changed; reload before editing.']);
            }
            // Editing a scheduled post returns it to draft, requiring explicit rescheduling.
            $post->status = PostStatus::DRAFT;
            $post->scheduled_at = null;
            $post->save();
            $post->accounts()->sync($v['accounts']);
            $post->tags()->sync($v['tags'] ?? []);
            $post->versions()->delete();
            $post->versions()->createMany($v['versions']);
            if (!$id) DB::table('mixpost_scheduler_requests')->where('id', $request->id)->update(['post_id' => $post->id]);
            return $this->serialize($post->fresh());
        });
    }

    public function validatePayload(string $org, array $data): array
    {
        $v = Validator::make($data, \Inovector\Mixpost\Support\TikTokPostOptions::rules() + [
            'accounts' => ['present', 'array', 'max:30'], 'accounts.*' => ['required', 'uuid', 'distinct', Rule::exists('mixpost_accounts', 'id')->where('organization_id', $org)],
            'tags' => ['sometimes', 'array'], 'tags.*' => ['uuid', Rule::exists('mixpost_tags', 'id')->where('organization_id', $org)],
            'versions' => ['required', 'array', 'min:1', 'max:31'],
            'versions.*.account_id' => ['nullable', 'uuid'], 'versions.*.is_original' => ['required', 'boolean'],
            'versions.*.content' => ['required', 'array', 'size:1'],
            'versions.*.content.*.body' => ['present', 'nullable', 'string', 'max:5000'],
            'versions.*.content.*.media' => ['present', 'array', 'max:35'],
            'versions.*.content.*.media.*' => ['uuid', Rule::exists('mixpost_media', 'id')->where('organization_id', $org)],
        ])->validate();
        if (collect($v['versions'])->where('is_original', true)->count() !== 1) throw ValidationException::withMessages(['versions' => 'Exactly one original version is required.']);
        $seen = [];
        foreach ($v['versions'] as $version) {
            $account = $version['account_id'] ?? null;
            if (($account && !in_array($account, $v['accounts'], true)) || ($account && in_array($account, $seen, true)) || ($version['is_original'] && $account)) {
                throw ValidationException::withMessages(['versions' => 'Use one original version without an account and at most one version per selected account.']);
            }
            if ($account) $seen[] = $account;
        }
        return $v;
    }

    public function validate(string $org, string $id): array
    {
        $post = $this->post($org, $id);
        $post->load('accounts', 'versions');
        $errors = [];
        if ($post->accounts->isEmpty()) $errors[] = 'Select at least one account.';
        foreach ($post->accounts as $account) {
            if ((string) $account->organization_id !== $org || !$account->authorized) $errors[] = "{$account->name}: account is unavailable or disconnected.";
            $parser = new PostContentParser($account, $post);
            if ($account->provider === 'tiktok' && ($error = \Inovector\Mixpost\Support\TikTokPostOptions::error($parser->getVersionOptions()))) $errors[] = "{$account->name}: $error";
            $content = $parser->getVersionContent();
            $body = $parser->formatBody(data_get($content, '0.body', ''));
            $media = data_get($content, '0.media', []);
            $configs = $account->postConfigs();
            if (count($content) !== 1) $errors[] = "{$account->name}: exactly one content block is currently supported.";
            if (trim($body) === '' && !$media) $errors[] = "{$account->name}: content is empty.";
            $found = Media::forOrganization($org)->whereIn('id', $media)->get()->keyBy('id');
            if ($found->count() !== count(array_unique($media))) $errors[] = "{$account->name}: media is missing or belongs to another organization.";
            $items = collect($media)->map(fn ($id) => $found->get($id))->filter()->values();
            $tiktokPhotos = $account->provider === 'tiktok' && $items->contains(fn ($item) => \Inovector\Mixpost\Support\TikTokPostOptions::isPhoto($item));
            if (!$tiktokPhotos && mb_strlen($body) > data_get($configs, 'text_char_limit.max.default', 5000)) $errors[] = "{$account->name}: text exceeds the provider limit.";
            foreach (['photos' => 'image/', 'videos' => 'video/', 'gifs' => 'image/gif'] as $kind => $mime) {
                $count = $items->filter(fn ($m) => $kind === 'photos' ? str_starts_with($m->mime_type, $mime) && $m->mime_type !== 'image/gif' : str_starts_with($m->mime_type, $mime))->count();
                if ($count > data_get($configs, "media_limit.max.$kind.default", 0)) $errors[] = "{$account->name}: too many $kind.";
            }
            if (in_array($account->provider, ['instagram', 'pinterest', 'tiktok', 'youtube'], true) && $items->isEmpty()) $errors[] = "{$account->name}: media is required.";
            if ($account->provider === 'youtube' && !$items->contains(fn ($m) => str_starts_with((string) $m->mime_type, 'video/'))) $errors[] = "{$account->name}: video is required.";
            if ($account->provider === 'tiktok' && ($error = \Inovector\Mixpost\Support\TikTokPostOptions::compositionError($items, $parser->getVersionOptions(), $body))) $errors[] = "{$account->name}: $error";
        }
        return ['valid' => !$errors, 'errors' => array_values(array_unique($errors)), 'post' => $this->serialize($post)];
    }

    public function schedule(string $org, string $id, string $at): array
    {
        Validator::make(['scheduled_at' => $at], ['scheduled_at' => ['required', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']])->validate();
        $instant = Carbon::parse($at)->utc();
        if ($instant->isPast()) throw ValidationException::withMessages(['scheduled_at' => 'Use a future ISO-8601 timestamp with timezone.']);
        return DB::transaction(function () use ($org, $id, $instant) {
            $post = $this->post($org, $id, true);
            $this->editable($post);
            $result = $this->validate($org, $id);
            if (!$result['valid']) throw ValidationException::withMessages(['post' => $result['errors']]);
            app(PostScheduleGuard::class)->assertAvailable($post, $instant, $post->accounts()->pluck('mixpost_accounts.id')->all());
            $post->setScheduled($instant);
            return $this->serialize($post);
        });
    }

    public function cancel(string $org, string $id): array
    {
        return DB::transaction(function () use ($org, $id) {
            $post = $this->post($org, $id, true);
            $this->editable($post);
            $post->scheduled_at = null;
            $post->setDraft();
            return $this->serialize($post);
        });
    }

    public function serialize(Post $post): array
    {
        $post->loadMissing('accounts', 'versions', 'tags');
        return [
            'id' => $post->uuid, 'status' => $post->isScheduleProcessing() ? 'PUBLISHING' : $post->status->name,
            'revision' => hash('sha256', json_encode([$post->getAttributes(), $post->versions->toArray(), $post->accounts->modelKeys(), $post->tags->modelKeys()], JSON_THROW_ON_ERROR)),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(), 'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'accounts' => $post->accounts->map(function ($a) {
                $data = json_decode($a->pivot->data ?? '{}', true) ?: [];
                return ['id' => $a->id, 'name' => $a->name, 'provider' => $a->provider,
                    'provider_post_id' => $a->pivot->provider_post_id, 'delivery_status' => $data['delivery_status'] ?? null,
                    'media_type' => $data['media_type'] ?? null, 'post_mode' => $data['post_mode'] ?? null, 'errors' => $a->pivot->errors];
            })->all(),
            'versions' => $post->versions->map(fn ($v) => $v->only('account_id', 'is_original', 'content', 'options'))->all(),
            'tags' => $post->tags->modelKeys(),
        ];
    }

    private function editable(Post $post): void
    {
        if ($post->isInHistory() || $post->isScheduleProcessing()) throw ValidationException::withMessages(['post' => 'Published, failed, or processing posts cannot be rescheduled or edited. Create a new draft instead.']);
    }
}
