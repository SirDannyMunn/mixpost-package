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
                // Add the current time + 1 minute for the `scheduled_at` field without save it into database.
                // canSchedule method require that the `scheduled_at` field is not null and not in the past.
                $this->post->setAttribute('scheduled_at', Carbon::now()->utc()->addMinute());
            }

            if (!$this->post->canSchedule()) {
                $validator->errors()->add('cannot_scheduled', "This post cannot be scheduled!\nThe date is in the past.");
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
                $options = $parser->getVersionOptions();
                if ($error = \Inovector\Mixpost\Support\TikTokPostOptions::error($options)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['tiktok' => $error]);
                }
                $content = $parser->getVersionContent();
                $media = $parser->formatMedia($content[0]['media'] ?? []);
                if ($error = \Inovector\Mixpost\Support\TikTokPostOptions::compositionError($media, $options, $parser->formatBody(data_get($content, '0.body', '')))) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['tiktok' => $error]);
                }
            }
            if (!$instant || $instant->isPast() && !$this->input('postNow') || !$this->post->accounts()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['post' => 'Choose a connected account and a future time.']);
            }
            app(\Inovector\Mixpost\Support\PostScheduleGuard::class)->assertAvailable($this->post, $instant, $this->post->accounts()->pluck('mixpost_accounts.id')->all());
            $this->post->setScheduled($instant);
        });
    }

    public function getDateTime(): Carbon|\Carbon\Carbon
    {
        return $this->input('postNow') ? Carbon::now()->utc() : $this->post->scheduled_at;
    }
}
