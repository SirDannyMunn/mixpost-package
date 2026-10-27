<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\{PostStatus, PostScheduleStatus};
use Inovector\Mixpost\Models\Post;

class CheckTikTokPostStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesSocialProviderManager;

    public int $tries = 3;
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Post $post, public int $checks = 0) {}

    public function handle(): void
    {
        $this->post->refresh();
        if (!$this->post->isScheduleProcessing()) return;
        $pending = false;
        $inbox = false;
        foreach ($this->post->accounts as $account) {
            if ($account->provider !== 'tiktok' || !$account->pivot->provider_post_id || $account->pivot->errors) continue;
            $data = json_decode($account->pivot->data ?? '{}', true) ?: [];
            if (($data['delivery_status'] ?? '') === 'PUBLISH_COMPLETE') continue;
            if (($data['delivery_status'] ?? '') === 'SEND_TO_USER_INBOX') { $inbox = true; continue; }
            try {
                $response = $this->connectProvider($account)->publishStatus($account->pivot->provider_post_id);
                if ($response->hasError()) { $pending = true; continue; }
                $result = $response->context();
                $state = $result['status'];
                $data['delivery_status'] = $state;
                $data['public_post_ids'] = $result['publicaly_available_post_id'] ?? [];
                $this->post->accounts()->updateExistingPivot($account->id, ['data' => json_encode($data)]);
                if ($state === 'FAILED') {
                    $reason = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($result['fail_reason'] ?? 'processing_failed'));
                    $this->post->insertErrors($account, ["TikTok processing failed: $reason"]);
                } elseif ($state === 'SEND_TO_USER_INBOX') {
                    $inbox = true;
                } elseif ($state !== 'PUBLISH_COMPLETE') {
                    $pending = true;
                }
            } catch (\Throwable $error) {
                $pending = true;
            }
        }
        if ($pending && $this->checks < 288) {
            self::dispatch($this->post, $this->checks + 1)->delay(now()->addMinutes(5));
            return;
        }
        if ($pending) {
            foreach ($this->post->accounts as $account) {
                $data = json_decode($account->pivot->data ?? '{}', true) ?: [];
                if ($account->provider === 'tiktok' && !in_array($data['delivery_status'] ?? '', ['PUBLISH_COMPLETE', 'SEND_TO_USER_INBOX', 'FAILED'], true)) {
                    $this->post->insertErrors($account, ['TikTok status could not be confirmed within 24 hours. Check TikTok before reposting.']);
                }
            }
        }
        if ($this->post->hasErrors()) { $this->post->setFailed(); return; }
        if ($inbox) {
            $this->post->status = PostStatus::DELIVERED;
            $this->post->schedule_status = PostScheduleStatus::PROCESSED;
            $this->post->save();
            return;
        }
        $this->post->setPublished();
    }
}
