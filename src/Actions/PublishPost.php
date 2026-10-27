<?php

namespace Inovector\Mixpost\Actions;

use Illuminate\Support\Facades\Bus;
use Inovector\Mixpost\Jobs\AccountPublishPostJob;
use Inovector\Mixpost\Models\Post;

class PublishPost
{
    public function __invoke(Post $post): void
    {
        $claimed = \Illuminate\Support\Facades\DB::transaction(function () use ($post) {
            $current = Post::whereKey($post->id)->lockForUpdate()->first();
            if (!$current || !$current->isScheduled() || $current->isScheduleProcessing() || !$current->scheduled_at || $current->scheduled_at->isFuture()) return false;
            $current->setScheduleProcessing();
            return true;
        });
        if (!$claimed) return;
        $post->refresh();

        $jobs = $post->accounts->map(function ($account) use ($post) {
            return new AccountPublishPostJob($account, $post);
        });

        Bus::batch($jobs)
            ->allowFailures()
            ->finally(function (\Illuminate\Bus\Batch $batch) use ($post) {
                if ($batch->failedJobs > 0) {
                    foreach ($post->accounts()->get() as $account) {
                        if (!$account->pivot->provider_post_id && !$account->pivot->errors) {
                            $post->insertErrors($account, ['Publishing could not be confirmed. Check the provider before retrying.']);
                        }
                    }
                }
                if ($post->accounts()->where('provider', 'tiktok')->wherePivotNotNull('provider_post_id')->exists()) {
                    \Inovector\Mixpost\Jobs\CheckTikTokPostStatus::dispatch($post)->delay(now()->addSeconds(20));
                    return;
                }
                if ($post->hasErrors()) {
                    $post->setFailed();
                    return;
                }

                $post->setPublished();
            })
            ->dispatch();
    }
}
