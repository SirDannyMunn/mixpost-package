<?php

namespace Inovector\Mixpost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Inovector\Mixpost\Models\Post;

class SchedulePost extends FormRequest
{
    public Post $post;

    public function rules(): array
    {
        return [
            'postNow' => ['required', 'boolean']
        ];
    }

    public function withValidator($validator)
    {
        $this->post = Post::firstOrFailByUuid($this->route('post'));

        $validator->after(function ($validator) {
            if ($this->post->isInHistory()) {
                $validator->errors()->add('in_history', 'in_history');
            }

            if ($this->post->isScheduleProcessing()) {
                $validator->errors()->add('publishing', 'publishing');
            }

            if ($this->input('postNow')) {
                // Validate immediate publishing with a non-null scheduled time.
                $this->post->setAttribute('scheduled_at', Carbon::now()->utc());
            }

            if (!$this->post->canSchedule()) {
                $validator->errors()->add('cannot_scheduled', "Choose a connected account and a publish date and time.");
            }
        });
    }

    public function handle(): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            $this->post = Post::forCurrentOrganization()->whereKey($this->post->id)->lockForUpdate()->firstOrFail();
            if ($this->post->isInHistory() || $this->post->isScheduleProcessing()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['post' => 'This post can no longer be scheduled.']);
            }
            $instant = $this->getDateTime();
            foreach ($this->post->accounts as $account) {
                if ($account->provider !== 'tiktok') continue;
                $parser = new \Inovector\Mixpost\Support\PostContentParser($account, $this->post);
                $mediaIds = data_get($parser->getVersionContent(), '0.media', []);
                $media = \Inovector\Mixpost\Models\Media::forOrganization($this->post->organization_id)->whereIn('id', $mediaIds)->get();
                if ($media->count() !== count($mediaIds) || ($error = \Inovector\Mixpost\Support\TikTokPostMedia::error($media))) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['tiktok' => $error ?? 'Some post media is unavailable.']);
                }
                if ($error = \Inovector\Mixpost\Support\TikTokPostOptions::error($parser->getVersionOptions())) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['tiktok' => $error]);
                }
            }
            if (!$instant || !$this->post->accounts()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['post' => 'Choose a connected account and a publish date and time.']);
            }
            app(\Inovector\Mixpost\Support\PostScheduleGuard::class)->assertAvailable($this->post, $instant, $this->post->accounts()->pluck('mixpost_accounts.id')->all());
            $this->post->setScheduled($instant);
        });
    }

    public function getDateTime(): Carbon|\Carbon\Carbon|null
    {
        return $this->input('postNow') ? Carbon::now()->utc() : $this->post->scheduled_at;
    }
}
